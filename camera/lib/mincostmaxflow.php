<?php
// mincostmaxflow.php -- HotCRP min-cost max-flow
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MinCostMaxFlow_Node {
    public $name;
    public $vindex;
    public $klass;
    public $flow = 0;
    public $link = null;
    public $xlink = null;
    public $npos = 0;
    public $distance = 0;
    public $excess = 0;
    public $price = 0;
    public $n_outgoing_admissible = 0;
    public $e = array();
    public $xe;
    public function __construct($name, $klass) {
        $this->name = $name;
        $this->klass = $klass;
    }
    public function check_excess($expected) {
        foreach ($this->e as $e)
            $expected += $e->flow_to($this);
        if ($expected != $this->excess)
            fwrite(STDERR, "{$this->name}: bad excess e{$this->excess}, have $expected\n");
        assert($expected == $this->excess);
    }
    public function count_outgoing_price_admissible() {
        $n = 0;
        foreach ($this->e as $e)
            if ($e->is_price_admissible_from($this))
                ++$n;
        return $n;
    }
    public function set_price_fix_admissible($p) {
        $old_price = $this->price;
        $this->price = $p;

        // adjust n_outgoing_admissible counts
        foreach ($this->e as $e) {
            $rc = $e->cost + $e->src->price - $e->dst->price;
            if ($e->src === $this)
                $old_rc = $e->cost + $old_price - $e->dst->price;
            else
                $old_rc = $e->cost + $e->src->price - $old_price;
            if (($rc < 0) !== ($old_rc < 0) && $e->flow < $e->cap)
                $e->src->n_outgoing_admissible += $rc < 0 ? 1 : -1;
            if (($rc > 0) !== ($old_rc > 0) && $e->flow > $e->mincap)
                $e->dst->n_outgoing_admissible += $rc > 0 ? 1 : -1;
        }
    }
};

class MinCostMaxFlow_Edge {
    public $src;
    public $dst;
    public $cap;
    public $mincap;
    public $cost;
    public $flow = 0;
    public function __construct($src, $dst, $cap, $cost, $mincap = 0) {
        $this->src = $src;
        $this->dst = $dst;
        $this->cap = $cap;
        $this->mincap = $mincap;
        $this->cost = $cost;
    }
    public function other($v) {
        return $v === $this->src ? $this->dst : $this->src;
    }
    public function flow_to($v) {
        return $v === $this->dst ? $this->flow : -$this->flow;
    }
    public function residual_cap($isrev) {
        return $isrev ? $this->flow - $this->mincap : $this->cap - $this->flow;
    }
    public function residual_cap_from($v) {
        return $v === $this->src ? $this->cap - $this->flow : $this->flow - $this->mincap;
    }
    public function residual_cap_to($v) {
        return $v === $this->src ? $this->flow - $this->mincap : $this->cap - $this->flow;
    }
    public function reduced_cost($isrev) {
        $c = $this->cost + $this->src->price - $this->dst->price;
        return $isrev ? -$c : $c;
    }
    public function reduced_cost_from($v) {
        return $this->reduced_cost($v === $this->dst);
    }
    public function is_distance_admissible_from($v) {
        if ($v === $this->src)
            return $this->flow < $this->cap && $v->distance == $this->dst->distance + 1;
        else
            return $this->flow > $this->mincap && $v->distance == $this->src->distance + 1;
    }
    public function is_price_admissible_from($v) {
        $c = $this->cost + $this->src->price - $this->dst->price;
        if ($v === $this->src)
            return $this->flow < $this->cap && $c < 0;
        else
            return $this->flow > $this->mincap && $c > 0;
    }
};

class MinCostMaxFlow {
    private $v = array();
    private $vmap;
    private $source;
    private $sink;
    private $e = array();
    private $maxflow = null;
    private $maxcap;
    private $mincost;
    private $maxcost;
    private $progressf = array();
    private $hasrun;
    private $debug;
    // times
    public $maxflow_start_at = null;
    public $maxflow_end_at = null;
    public $mincost_start_at = null;
    public $mincost_end_at = null;
    // pushrelabel/cspushrelabel state
    private $epsilon;
    private $ltail;
    public $npush;
    public $nrelabel;

    const PMAXFLOW = 0;
    const PMAXFLOW_DONE = 1;
    const PMINCOST_BEGINROUND = 2;
    const PMINCOST_INROUND = 3;
    const PMINCOST_DONE = 4;

    const CSPUSHRELABEL_ALPHA = 12;

    const DEBUG = 1;

    public function __construct($flags = false) {
        $this->clear();
        $this->debug = ($flags & self::DEBUG) != 0;
    }

    public function add_node($name, $klass = "") {
        if ($name === "")
            $name = ".v" . count($this->v);
        assert(is_string($name) && !isset($this->vmap[$name]));
        $v = new MinCostMaxFlow_Node($name, $klass);
        $this->v[] = $this->vmap[$name] = $v;
        return $v;
    }

    public function add_edge($vs, $vd, $cap, $cost = 0, $mincap = 0) {
        if (is_string($vs))
            $vs = $this->vmap[$vs];
        if (is_string($vd))
            $vd = $this->vmap[$vd];
        assert(($vs instanceof MinCostMaxFlow_Node) && ($vd instanceof MinCostMaxFlow_Node));
        assert($vs !== $this->sink && $vd !== $this->source && $vs !== $vd);
        // XXX assert(this edge does not exist)
        $this->e[] = new MinCostMaxFlow_Edge($vs, $vd, $cap, $cost, $mincap);
        $this->maxcap = max($this->maxcap, $cap);
        $this->mincost = min($this->mincost, $cost);
        $this->maxcost = max($this->maxcost, $cost);
    }

    public function add_progressf($progressf) {
        $this->progressf[] = $progressf;
    }


    // extract information

    public function node_exists($name) {
        return isset($this->vmap[$name]);
    }

    public function nodes($klass) {
        $a = array();
        foreach ($this->v as $v)
            if ($v->klass === $klass)
                $a[] = $v;
        return $a;
    }

    public function current_flow() {
        if ($this->maxflow !== null)
            return $this->maxflow;
        else
            return min(-$this->source->excess, $this->sink->excess);
    }

    public function current_cost() {
        $cost = 0;
        foreach ($this->e as $e)
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        return $cost;
    }

    private function add_reachable($v, $klass, &$a) {
        if ($v->klass === $klass)
            $a[] = $v;
        else if ($v !== $this->sink) {
            foreach ($v->e as $e)
                if ($e->src === $v && $e->flow > 0)
                    $this->add_reachable($e->dst, $klass, $a);
        }
    }

    public function reachable($v, $klass) {
        if (is_string($v))
            $v = $this->vmap[$v];
        $a = array();
        $this->add_reachable($v, $klass, $a);
        return $a;
    }

    private function topological_sort_visit($v, $klass, &$a) {
        if ($v !== $this->sink && !$v->npos) {
            $v->npos = 1;
            foreach ($v->e as $e)
                if ($e->src === $v && $e->flow > 0 && !$e->dst->npos)
                    $this->topological_sort_visit($e->dst, $klass, $a);
            if ($v->klass === $klass)
                $a[] = $v;
        }
    }

    public function topological_sort($v, $klass) {
        if (is_string($v))
            $v = $this->vmap[$v];
        foreach ($this->v as $vx)
            $vx->npos = 0;
        $a = array();
        $this->topological_sort_visit($v, $klass, $a);
        return array_reverse($a);
    }


    // internals

    private function initialize_edges() {
        foreach ($this->e as $e)
            $e->src->e[] = $e;
        foreach ($this->e as $e)
            $e->dst->e[] = $e;
    }


    // push-relabel: maximum flow only, ignores costs

    private static function pushrelabel_bfs_setdistance($qtail, $v, $dist) {
        if ($v->distance !== $dist) {
            $v->distance = $dist;
            $v->npos = 0;
        }
        $v->xlink = null;
        $qtail->xlink = $v;
        return $v;
    }

    private function pushrelabel_make_distance() {
        foreach ($this->v as $v)
            $v->xlink = $this->sink;
        $qhead = $qtail = $this->sink;
        $qhead->distance = 0;
        $qhead->xlink = null;
        while ($qhead) {
            $d = $qhead->distance + 1;
            foreach ($qhead->e as $e)
                if ($e->residual_cap_to($qhead) > 0
                    && $e->other($qhead)->xlink === $this->sink)
                    $qtail = self::pushrelabel_bfs_setdistance($qtail, $e->other($qhead), $d);
            $qhead = $qhead->xlink;
        }
    }

    private function pushrelabel_push_from($e, $src) {
        $amt = min($src->excess, $e->residual_cap_from($src));
        $amt = ($src == $e->dst ? -$amt : $amt);
        //fwrite(STDERR, "push {$amt} {$e->src->name}@{$e->src->distance} -> {$e->dst->name}@{$e->dst->distance}\n");
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
    }

    private function pushrelabel_relabel($v) {
        $d = INF;
        foreach ($v->e as $e)
            if ($e->residual_cap_from($v) > 0)
                $d = min($d, $e->other($v)->distance + 1);
        //fwrite(STDERR, "relabel {$v->name}@{$v->distance}->{$d}\n");
        $v->distance = $d;
        $v->npos = 0;
    }

    private function pushrelabel_discharge($v) {
        $ne = count($v->e);
        $relabeled = false;
        while ($v->excess > 0) {
            if ($v->npos == $ne) {
                $this->pushrelabel_relabel($v);
                $relabeled = true;
            } else {
                $e = $v->e[$v->npos];
                if ($e->is_distance_admissible_from($v))
                    $this->pushrelabel_push_from($e, $v);
                else
                    ++$v->npos;
            }
        }
        return $relabeled;
    }

    private function pushrelabel_run() {
        $this->maxflow_start_at = microtime(true);

        // initialize preflow
        foreach ($this->v as $v) {
            $v->distance = count($this->v);
            $v->npos = 0;
        }
        $this->pushrelabel_make_distance();
        foreach ($this->e as $e) {
            if ($e->src === $this->source)
                $f = $e->cap;
            else if ($e->mincap > 0)
                $f = $e->mincap;
            else
                continue;
            $e->flow = $f;
            $e->dst->excess += $f;
            $e->src->excess -= $f;
        }

        // initialize list
        $lhead = $ltail = null;
        foreach ($this->v as $v)
            if ($v !== $this->source && $v !== $this->sink) {
                $ltail ? ($ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $ltail = $v;
            }

        // relabel-to-front
        $n = 0;
        $l = $lhead;
        $lprev = null;
        $max_distance = 2 * count($this->v) - 1;
        $this->nrelabel = 0;
        while ($l) {
            // check progress
            ++$n;
            if ($n % 32768 == 0)
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMAXFLOW);

            // discharge current vertex
            if ($this->pushrelabel_discharge($l)) {
                // global relabeling heuristic is quite useful
                ++$this->nrelabel;
                if ($this->nrelabel % count($this->v) == 0)
                    $this->pushrelabel_make_distance();
                // if relabeled, put back on front
                if ($l !== $lhead) {
                    $lprev->link = $l->link;
                    $l->link = $lhead;
                    $lhead = $l;
                }
            }
            assert($l->distance <= $max_distance);
            $lprev = $l;
            $l = $l->link;

            // thanks to global relabeling heuristic, may still have active
            // nodes; go one more time through
            if (!$l) {
                $lprev = null;
                for ($l = $lhead; $l && $l->excess == 0; ) {
                    $lprev = $l;
                    $l = $l->link;
                }
            }
        }

        $this->maxflow = $this->sink->excess;
        $this->source->excess = $this->sink->excess = 0;
        $this->maxflow_end_at = microtime(true);
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMAXFLOW_DONE);
    }


    // cost-scaling push-relabel

    private function cspushrelabel_push_from($e, $src) {
        $dst = $e->other($src);
        // push lookahead heuristic
        if ($dst->excess >= 0 && !$dst->n_outgoing_admissible) {
            $this->debug && fwrite(STDERR, "push lookahead {$src->name} > {$dst->name}\n");
            $this->cspushrelabel_relabel($dst);
            return;
        }

        $amt = min($src->excess, $e->residual_cap_from($src));
        $amt = ($e->src === $src ? $amt : -$amt);
        $e->flow += $amt;
        $e->src->excess -= $amt;
        $e->dst->excess += $amt;
        if (!$e->residual_cap_from($src))
            --$src->n_outgoing_admissible;

        if ($dst->excess > 0 && $dst->link === false) {
            $this->ltail = $this->ltail->link = $dst;
            $dst->link = null;
        }
        $this->debug && fwrite(STDERR, "push $amt {$e->src->name} > {$e->dst->name}\n");
        ++$this->npush;
    }

    private function cspushrelabel_relabel($v) {
        // calculate new price
        $p = -INF;
        foreach ($v->e as $epos => $e) {
            if ($e->src === $v && $e->flow < $e->cap)
                $px = $e->dst->price - $e->cost;
            else if ($e->dst === $v && $e->flow > $e->mincap)
                $px = $e->src->price + $e->cost;
            else
                continue;
            if ($px > $p) {
                $p = $px;
                $ex = $epos;
            }
        }
        assert($p != -INF || $v->excess == 0);
        if ($p > -INF)
            $v->npos = $ex;
        else
            $p = $v->price;
        $p -= $this->epsilon;
        $this->debug && fwrite(STDERR, "relabel {$v->name} E{$v->excess} @{$v->price}->{$p}\n");
        $v->set_price_fix_admissible($p);
        ++$this->nrelabel;
    }

    private function cspushrelabel_discharge($v) {
        $ne = count($v->e);
        while ($v->excess > 0) {
            if ($v->npos == $ne || !$v->n_outgoing_admissible)
                $this->cspushrelabel_relabel($v);
            else {
                $e = $v->e[$v->npos];
                if ($e->is_price_admissible_from($v))
                    $this->cspushrelabel_push_from($e, $v);
                else
                    ++$v->npos;
            }
        }
    }

    private function cspushrelabel_reprice() {
        $max_distance = (int) (2 * (count($this->v) + 1) * self::CSPUSHRELABEL_ALPHA + 2);
        $excess = 0;

        // initialize $b[0] with negative-excess nodes
        $b = array(array());
        foreach ($this->v as $v) {
            if ($v->excess < 0) {
                $v->distance = 0;
                $b[0][] = $v;
            } else
                $v->distance = $max_distance;
            $excess += max($v->excess, 0);
        }
        $total_excess = $excess;

        // loop over buckets, pricing one node at a time
        $bi = 0;
        while ($bi < count($b) && $excess > 0) {
            if (!get($b, $bi)) {
                ++$bi;
                continue;
            }
            $v = array_pop($b[$bi]);
            if ($v->distance !== $bi)
                continue;
            foreach ($v->e as $ei => $e)
                if ($e->residual_cap_to($v)
                    && ($dst = $e->other($v))
                    && $bi < $dst->distance) {
                    $nd = $bi;
                    $cost = $e->reduced_cost_from($dst);
                    if ($cost >= 0)
                        $nd += 1 + (int) min($max_distance, $cost / $this->epsilon);
                    if ($nd < $dst->distance) {
                        $dst->distance = $nd;
                        while (count($b) <= $nd)
                            $b[] = array();
                        $b[$nd][] = $dst;
                    }
                }
            if ($bi)
                $v->set_price_fix_admissible($v->price - $bi * $this->epsilon);
            $v->distance = -1;
            $excess -= max($v->excess, 0);
        }

        // reduce prices for unexamined nodes
        if ($total_excess && $bi)
            foreach ($this->v as $v)
                if ($v->distance >= 0)
                    $v->set_price_fix_admissible($v->price - $bi * $this->epsilon);
    }

    private function cspushrelabel_refine($phaseno, $nphases) {
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_BEGINROUND, $phaseno, $nphases);

        // arc fixing; note that Goldberg 1997's description of arc fixing
        // is incorrect/misleading -- we care about the absolute value of
        // REDUCED costs, not original costs; use the lower fixbound from
        // Goldberg & Tarjan 1990, rather than 2*n*epsilon.
        $fixbound = count($this->v) * $this->epsilon * (1 + 1.0 / self::CSPUSHRELABEL_ALPHA);
        if (max(abs($this->mincost), $this->maxcost) >= $fixbound) {
            $ndropped = 0;
            foreach ($this->v as $v)
                for ($i = 0; $i < count($v->e); ) {
                    $e = $v->e[$i];
                    if (abs($e->reduced_cost(false)) >= $fixbound) {
                        if ($e->is_price_admissible_from($v))
                            --$v->n_outgoing_admissible;
                        $v->xe[] = $e;
                        $v->e[$i] = $v->e[count($v->e) - 1];
                        array_pop($v->e);
                        $v->npos = 0; // keep npos in bounds
                        ++$ndropped;
                    } else
                        ++$i;
                }
            $this->debug && $ndropped && fwrite(STDERR, "dropedge $ndropped\n");
        }

        // generate output
        //$tempnam = tempnam(sys_get_temp_dir(), "mincost.$phaseno");
        //file_put_contents($tempnam, $this->mincost_dimacs_input() . $this->mincost_dimacs_output());

        // reduce epsilon
        $this->epsilon = $this->epsilon / self::CSPUSHRELABEL_ALPHA;
        $this->debug && fwrite(STDERR, "phase " . (1 + $phaseno) . " epsilon $this->epsilon\n");

        // saturate negative-cost arcs
        foreach ($this->v as $v)
            if ($v->n_outgoing_admissible) {
                foreach ($v->e as $e)
                    if ($e->is_price_admissible_from($v)) {
                        $delta = ($e->src === $v ? $e->cap : $e->mincap) - $e->flow;
                        $e->flow += $delta;
                        $e->dst->excess += $delta;
                        $e->src->excess -= $delta;
                        --$v->n_outgoing_admissible;
                    }
            }

        // initialize lists and neighbor position
        $lhead = $this->ltail = null;
        foreach ($this->v as $v) {
            $v->npos = 0;
            if ($v->excess > 0) {
                $this->ltail ? ($this->ltail->link = $v) : ($lhead = $v);
                $v->link = null;
                $this->ltail = $v;
            } else
                $v->link = false;
        }

        // price update heuristic
        $this->cspushrelabel_reprice();

        // repeated discharge
        $n = 0;
        while ($lhead) {
            // check progress
            if ($this->npush + $this->nrelabel - $n >= 2048) {
                foreach ($this->progressf as $progressf)
                    call_user_func($progressf, $this, self::PMINCOST_INROUND, $phaseno, $nphases);
                $n = $this->npush + $this->nrelabel;
            }

            // discharge current vertex
            $this->cspushrelabel_discharge($lhead);

            $l = $lhead->link;
            $lhead->link = false;
            $lhead = $l;
        }
    }

    public function cspushrelabel_check($allow_excess = false) {
        if (!$allow_excess)
            foreach ($this->v as $v)
                if ($v->excess > 0)
                    fwrite(STDERR, "BUG: node {$v->name} has positive excess {$v->excess}\n");

        $ebound = -$this->epsilon - (0.5 / count($this->v));
        foreach ($this->e as $e) {
            if ($e->flow < $e->cap && $e->reduced_cost(false) < $ebound)
                fwrite(STDERR, "BUG: residual arc {$e->src->name} > {$e->dst->name} ({$e->flow}/{$e->cap} \${$e->cost}) has reduced cost " . $e->reduced_cost(false) . " < " . -$this->epsilon . "\n");
            if ($e->flow > $e->mincap && $e->reduced_cost(true) < $ebound)
                fwrite(STDERR, "BUG: residual arc {$e->src->name} < {$e->dst->name} (" . (-$e->flow) . "/{$e->mincap} \$" . -$e->cost . ") has reduced cost " . $e->reduced_cost(true) . " < " . -$this->epsilon . "\n");
        }
    }

    public function cspushrelabel_finish() {
        // refine the maximum flow to achieve min cost
        $this->mincost_start_at = microtime(true);
        $this->npush = $this->nrelabel = 0;
        $phaseno = $nphases = 0;
        for ($e = $this->epsilon; $e >= 1 / count($this->v); $e /= self::CSPUSHRELABEL_ALPHA)
            ++$nphases;

        foreach ($this->v as $v) {
            $v->n_outgoing_admissible = $v->count_outgoing_price_admissible();
            $v->xe = array();
        }

        $this->debug && $this->cspushrelabel_check();
        while ($this->epsilon >= 1 / count($this->v)) {
            $this->cspushrelabel_refine($phaseno, $nphases);
            $this->debug && $this->cspushrelabel_check();
            ++$phaseno;
        }
        $this->mincost_end_at = microtime(true);

        foreach ($this->v as $v)
            if ($v->xe) {
                $v->e = array_merge($v->e, $v->xe);
                $v->xe = null;
            }
        foreach ($this->progressf as $progressf)
            call_user_func($progressf, $this, self::PMINCOST_DONE);
    }


    public function shuffle() {
        // shuffle vertices and edges because edge order affects which
        // circulations we choose; this randomizes the assignment
        shuffle($this->v);
        shuffle($this->e);
    }

    public function run() {
        assert(!$this->hasrun);
        $this->hasrun = true;
        $this->initialize_edges();
        $this->pushrelabel_run();
        if ($this->mincost != 0 || $this->maxcost != 0) {
            $this->epsilon = max(abs($this->mincost), $this->maxcost);
            $this->cspushrelabel_finish();
        }
    }


    public function reset() {
        if ($this->hasrun) {
            foreach ($this->v as $v) {
                $v->distance = $v->excess = $v->price = 0;
                $v->e = array();
            }
            foreach ($this->e as $e)
                $e->flow = 0;
            $this->maxflow = null;
            $this->maxflow_start_at = $this->maxflow_end_at = null;
            $this->mincost_start_at = $this->mincost_end_at = null;
            $this->hasrun = false;
        }
    }

    public function clear() {
        // break circular references
        foreach ($this->v as $v)
            $v->link = $v->xlink = $v->e = null;
        foreach ($this->e as $e)
            $e->src = $e->dst = null;
        $this->v = array();
        $this->e = array();
        $this->vmap = array();
        $this->maxflow = null;
        $this->maxcap = $this->mincost = $this->maxcost = 0;
        $this->source = $this->add_node(".source", ".internal");
        $this->sink = $this->add_node(".sink", ".internal");
        $this->hasrun = false;
    }

    public function debug_info($only_flow = false) {
        $ex = array();
        $cost = 0;
        foreach ($this->e as $e) {
            if ($e->flow || $e->mincap || !$only_flow)
                $ex[] = "{$e->src->name} {$e->dst->name} $e->mincap $e->cap $e->cost $e->flow\n";
            if ($e->flow)
                $cost += $e->flow * $e->cost;
        }
        usort($ex, "strnatcmp");
        $vx = array();
        foreach ($this->v as $v)
            if ($v->excess)
                $vx[] = "E {$v->name} {$v->excess}\n";
        usort($vx, "strnatcmp");
        $x = "";
        if ($this->hasrun)
            $x = "total {$e->flow} $cost\n";
        return $x . join("", $ex) . join("", $vx);
    }


    private function dimacs_input($mincost) {
        $x = array("p " . ($mincost ? "min" : "max") . " "
                   . count($this->v) . " " . count($this->e) . "\n");
        foreach ($this->v as $i => $v)
            $v->vindex = $i + 1;
        if ($mincost && $this->maxflow) {
            $x[] = "n {$this->source->vindex} {$this->maxflow}\n";
            $x[] = "n {$this->sink->vindex} -{$this->maxflow}\n";
        } else {
            $x[] = "n {$this->source->vindex} s\n";
            $x[] = "n {$this->sink->vindex} t\n";
        }
        foreach ($this->v as $v)
            if ($v !== $this->source && $v !== $this->sink) {
                $cmt = "c ninfo {$v->vindex} {$v->name}";
                if ($v->klass !== "")
                    $cmt .= " {$v->klass}";
                $x[] = "$cmt\n";
            }
        if ($mincost) {
            foreach ($this->e as $e)
                $x[] = "a {$e->src->vindex} {$e->dst->vindex} {$e->mincap} {$e->cap} {$e->cost}\n";
        } else {
            foreach ($this->e as $e)
                $x[] = "a {$e->src->vindex} {$e->dst->vindex} {$e->cap}\n";
        }
        return join("", $x);
    }

    public function maxflow_dimacs_input() {
        return $this->dimacs_input(false);
    }

    public function mincost_dimacs_input() {
        return $this->dimacs_input(true);
    }


    private function dimacs_output($mincost) {
        $x = array("c p " . ($mincost ? "min" : "max") . " "
                   . count($this->v) . " " . count($this->e) . "\n");
        foreach ($this->v as $i => $v)
            $v->vindex = $i + 1;
        if ($mincost) {
            $x[] = "s " . $this->current_cost() . "\n";
            $x[] = "c flow " . $this->current_flow() . "\n";
            $x[] = "c min_epsilon " . $this->epsilon . "\n";
            foreach ($this->v as $v)
                if ($v->price != 0)
                    $x[] = "c nprice {$v->vindex} {$v->price}\n";
        } else
            $x[] = "s " . $this->current_flow() . "\n";
        foreach ($this->e as $e)
            if ($e->flow) {
                // is this flow ambiguous?
                $n = 0;
                foreach ($e->src->e as $ee)
                    if ($ee->dst === $e->dst)
                        ++$n;
                if ($n !== 1)
                    $x[] = "c finfo {$e->cap} {$e->cost}\n";
                $x[] = "f {$e->src->vindex} {$e->dst->vindex} {$e->flow}\n";
            }
        return join("", $x);
    }

    public function maxflow_dimacs_output() {
        return $this->dimacs_output(false);
    }

    public function mincost_dimacs_output() {
        return $this->dimacs_output(true);
    }


    private function dimacs_node(&$vnames, $num, $name = "", $klass = "") {
        if (!($v = get($vnames, $num)))
            $v = $vnames[$num] = $this->add_node($name, $klass);
        return $v;
    }

    public function parse_dimacs($str) {
        $this->reset();
        $vnames = array();
        $ismax = null;
        $next_cap = $next_cost = null;
        $has_edges = false;
        foreach (CsvParser::split_lines($str) as $lineno => $line) {
            if ($line[0] !== "f")
                $next_cap = $next_cost = null;
            if (preg_match('/\An (\d+) (-?\d+|s|t)\s*\z/', $line, $m)) {
                $issink = $m[2] === "t" || $m[2] < 0;
                assert(!get($vnames, $m[1]));
                $vnames[$m[1]] = $v = $issink ? $this->sink : $this->source;
                if ($m[2] !== "s" && $m[2] !== "t") {
                    $v->excess = (int) $m[2];
                    $this->maxflow = abs($v->excess);
                }
            } else if (preg_match('/\Ac ninfo (\d+) (\S+)\s*(\S*)\s*\z/', $line, $m)) {
                $this->dimacs_node($vnames, $m[1], $m[2], $m[3]);
            } else if (preg_match('/\Ac nprice (\d+) (\S+)\s*\z/', $line, $m)
                       && is_numeric($m[2])) {
                $v = $this->dimacs_node($vnames, $m[1]);
                $v->price = (float) $m[2];
            } else if (preg_match('/\Aa (\d+) (\d+) (\d+)\s*\z/', $line, $m)) {
                assert(!$has_edges);
                $this->add_edge($this->dimacs_node($vnames, $m[1]),
                                $this->dimacs_node($vnames, $m[2]),
                                (int) $m[3], 0);
            } else if (preg_match('/\Aa (\d+) (\d+) (\d+) (\d+) (-?\d+)\s*\z/', $line, $m)) {
                assert(!$has_edges);
                $this->add_edge($this->dimacs_node($vnames, $m[1]),
                                $this->dimacs_node($vnames, $m[2]),
                                (int) $m[4], (int) $m[5], (int) $m[3]);
            } else if (preg_match('/\Ac finfo (\d+)\s*(|-?\d+)\s*\z/', $line, $m)) {
                $next_cap = (int) $m[1];
                $next_cost = (int) $m[2];
            } else if (preg_match('/\Af (\d+) (\d+) (-?\d+)\s*\z/', $line, $m)) {
                if (!$has_edges) {
                    $this->initialize_edges();
                    $has_edges = true;
                }
                $src = $this->dimacs_node($vnames, $m[1]);
                $dst = $this->dimacs_node($vnames, $m[2]);
                $found = false;
                foreach ($src->e as $e)
                    if ($e->dst === $dst
                        && ($next_cap === null || $e->cap === $next_cap)
                        && ($next_cost === null || $e->cost === $next_cost)) {
                        $e->flow = (int) $m[3];
                        $src->excess -= $e->flow;
                        $dst->excess += $e->flow;
                        $found = true;
                        break;
                    }
                if (!$found)
                    error_log("MinCostMaxFlow::parse_dimacs: line " . ($lineno + 1) . ": no such edge");
                $next_cap = $next_cost = null;
            } else if (preg_match('/\As (\d+)\s*\z/', $line, $m)
                       && $this->source->excess === 0) {
                $this->source->excess = -(int) $m[1];
                $this->sink->excess = (int) $m[1];
                $this->maxflow = (int) $m[1];
            } else if (preg_match('/\Ac min_epsilon (\S+)\s*\z/', $line, $m)
                       && is_numeric($m[1])) {
                $this->epsilon = (float) $m[1];
            } else if ($line[0] === "a" || $line[0] === "f") {
                error_log("MinCostMaxFlow::parse_dimacs: line " . ($lineno + 1) . ": parse error");
            }
        }
        ksort($vnames, SORT_NUMERIC);
        $this->v = array_values($vnames);
    }
}

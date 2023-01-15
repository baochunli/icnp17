<?php
// text.php -- HotCRP text helper functions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class NameInfo {
    public $firstName = null;
    public $middleName = null;
    public $lastName = null;
    public $email = null;
    public $withMiddle = null;
    public $lastFirst = null;
    public $nameAmbiguous = null;
    public $name = null;
    public $affiliation = null;
    public $unaccentedName = null;
    static function make_last_first() {
        $ni = new NameInfo;
        $ni->lastFirst = true;
        return $ni;
    }
}

class Text {
    static private $argkeys = array("firstName", "lastName", "email",
                                    "withMiddle", "middleName", "lastFirst",
                                    "nameAmbiguous", "name");
    static private $defaults = array("firstName" => "",
                                     "lastName" => "",
                                     "email" => "",
                                     "withMiddle" => false,
                                     "middleName" => null,
                                     "lastFirst" => false,
                                     "nameAmbiguous" => false,
                                     "name" => null,
                                     "affiliation" => null);
    static private $mapkeys = array("firstName" => "firstName",
                                    "first" => "firstName",
                                    "lastName" => "lastName",
                                    "last" => "lastName",
                                    "email" => "email",
                                    "withMiddle" => "withMiddle",
                                    "middleName" => "middleName",
                                    "middle" => "middleName",
                                    "lastFirst" => "lastFirst",
                                    "nameAmbiguous" => "nameAmbiguous",
                                    "name" => "name",
                                    "fullName" => "name",
                                    "affiliation" => "affiliation");
    static private $boolkeys = array("withMiddle" => true,
                                     "lastFirst" => true,
                                     "nameAmbiguous" => true);
    static private $boring_words = [
        "a" => true, "an" => true, "as" => true, "be" => true,
        "by" => true, "did" => true, "do" => true, "for" => true,
        "in" => true, "is" => true, "of" => true, "on" => true,
        "the" => true, "this" => true, "through" => true, "to" => true,
        "with" => true
    ];

    static function analyze_von($lastName) {
        // see also split_name
        if (preg_match('@\A(v[oa]n|d[eu])\s+(.*)\z@s', $lastName, $m))
            return array($m[1], $m[2]);
        else
            return null;
    }

    static function analyze_name_args($args, $ret = null) {
        $ret = $ret ? : new NameInfo;
        $delta = 0;
        if (count($args) == 1 && is_string($args[0]))
            $args = self::split_name($args[0], true);
        foreach ($args as $i => $v) {
            if (is_string($v) || is_bool($v)) {
                if ($i + $delta < 4) {
                    $k = self::$argkeys[$i + $delta];
                    if (get($ret, $k) === null)
                        $ret->$k = $v;
                }
            } else if (is_array($v) && isset($v[0])) {
                for ($j = 0; $j < 3 && $j < count($v); ++$j) {
                    $k = self::$argkeys[$j];
                    if (get($ret, $k) === null)
                        $ret->$k = $v[$j];
                }
            } else if (is_array($v)) {
                foreach ($v as $k => $x)
                    if (($mk = get(self::$mapkeys, $k))
                        && get($ret, $mk) === null)
                        $ret->$mk = $x;
                $delta = 3;
            } else if (is_object($v)) {
                foreach (self::$mapkeys as $k => $mk)
                    if (get($ret, $mk) === null
                        && get($v, $k) !== null
                        && (get(self::$boolkeys, $mk)
                            ? is_bool($v->$k)
                            : is_string($v->$k)))
                        $ret->$mk = $v->$k;
            }
        }
        foreach (self::$defaults as $k => $v)
            if (get($ret, $k) === null)
                $ret->$k = $v;
        if ($ret->name && $ret->firstName === "" && $ret->lastName === "")
            list($ret->firstName, $ret->lastName) = self::split_name($ret->name);
        if ($ret->withMiddle && $ret->middleName) {
            $m = trim($ret->middleName);
            if ($m)
                $ret->firstName =
                    (isset($ret->firstName) ? $ret->firstName : "") . " " . $m;
        }
        if ($ret->lastFirst && ($m = self::analyze_von($ret->lastName))) {
            $ret->firstName = trim($ret->firstName . " " . $m[0]);
            $ret->lastName = $m[1];
        }
        if ($ret->lastName === "" || $ret->firstName === "")
            $ret->name = $ret->firstName . $ret->lastName;
        else if (get($ret, "lastFirst"))
            $ret->name = $ret->lastName . ", " . $ret->firstName;
        else
            $ret->name = $ret->firstName . " " . $ret->lastName;
        if ($ret->lastName === "" || $ret->firstName === "")
            $x = $ret->firstName . $ret->lastName;
        else
            $x = $ret->firstName . " " . $ret->lastName;
        if (preg_match('/[\x80-\xFF]/', $x))
            $x = UnicodeHelper::deaccent($x);
        $ret->unaccentedName = $x;
        return $ret;
    }

    static function analyze_name(/* ... */) {
        return self::analyze_name_args(func_get_args());
    }

    static function user_text(/* ... */) {
        // was contactText
        $r = self::analyze_name_args(func_get_args());
        if ($r->name && $r->email)
            return "$r->name <$r->email>";
        else
            return $r->name ? : $r->email;
    }

    static function user_html(/* ... */) {
        // was contactHtml
        $r = self::analyze_name_args(func_get_args());
        $e = htmlspecialchars($r->email);
        if ($e && strpos($e, "@") !== false)
            $e = "&lt;<a class=\"maillink\" href=\"mailto:$e\">$e</a>&gt;";
        else if ($e)
            $e = "&lt;$e&gt;";
        if ($r->name)
            return htmlspecialchars($r->name) . ($e ? " " . $e : "");
        else
            return $e ? : "[No name]";
    }

    static function user_html_nolink(/* ... */) {
        $r = self::analyze_name_args(func_get_args());
        if (($e = $r->email))
            $e = "&lt;" . htmlspecialchars($e) . "&gt;";
        if ($r->name)
            return htmlspecialchars($r->name) . ($e ? " " . $e : "");
        else
            return $e ? : "[No name]";
    }

    static function name_text(/* ... */) {
        // was contactNameText
        $r = self::analyze_name_args(func_get_args());
        if ($r->nameAmbiguous && $r->name && $r->email)
            return "$r->name <$r->email>";
        else
            return $r->name ? : $r->email;
    }

    static function name_html(/* ... */) {
        // was contactNameHtml
        $x = call_user_func_array("Text::name_text", func_get_args());
        return htmlspecialchars($x);
    }

    static function user_email_to(/* ... */) {
        // was contactEmailTo
        $r = self::analyze_name_args(func_get_args());
        if (!($e = $r->email))
            $e = "none";
        if (($n = $r->name)) {
            if (preg_match('/[\000-\037()[\]<>@,;:\\".]/', $n))
                $n = "\"" . addcslashes($n, '"\\') . "\"";
            return "$n <$e>";
        } else
            return $e;
    }

    static function initial($s) {
        $x = "";
        if ($s != null && $s != "") {
            if (ctype_alpha($s[0]))
                $x = $s[0];
            else if (preg_match("/^(\\pL)/us", $s, $m))
                $x = $m[1];
            // Don't add a period if first name is a single letter
            if ($x != "" && $x != $s && !str_starts_with($s, "$x "))
                $x .= ".";
        }
        return $x;
    }

    static function abbrevname_text(/* ... */) {
        $r = self::analyze_name_args(func_get_args());
        $u = "";
        if ($r->lastName) {
            $t = $r->lastName;
            if ($r->firstName && ($u = self::initial($r->firstName)) != "")
                $u .= " "; // non-breaking space
        } else if ($r->firstName)
            $t = $r->firstName;
        else
            $t = $r->email ? $r->email : "???";
        return $u . $t;
    }

    static function abbrevname_html(/* ... */) {
        // was abbreviateNameHtml
        $x = call_user_func_array("Text::abbrevname_text", func_get_args());
        return htmlspecialchars($x);
    }

    const SUFFIX_REGEX = 'Jr\.?|Sr\.?|Esq\.?|Ph\.?D\.?|M\.?[SD]\.?|Junior|Senior|Esquire|I+|IV|V|VI*|IX|XI*|2n?d|3r?d|[4-9]th|1\dth';

    static function split_name($name, $with_email = false) {
        $name = simplify_whitespace($name);
        $ret = array("", "");
        if ($with_email) {
            $ret[2] = "";
            if (preg_match('%^\s*\"?(.*?)\"?\s*<([^<>]+)>\s*$%', $name, $m)
                || preg_match('%^\s*\"(.*)\"\s+(\S+)\s*$%', $name, $m))
                list($name, $ret[2]) = array($m[1], $m[2]);
            else if (!preg_match('%^\s*(.*?)\s+(\S+)\s*$%', $name, $m))
                return array("", "", trim($name));
            else if (strpos($m[2], "@") !== false)
                list($name, $ret[2]) = array($m[1], $m[2]);
            else if (strpos($m[1], "@") !== false)
                list($name, $ret[2]) = array($m[2], $m[1]);
        }

        // parenthetical comment on name attaches to first or last whole
        $paren = "";
        if ($name !== "" && $name[strlen($name) - 1] === ")"
            && preg_match('{\A(.*?)(\s*\(.*?\))\z}', $name, $m)) {
            $name = $m[1];
            $paren = $m[2];
        }

        // `last, first`
        $suffix = "";
        while (($comma = strrpos($name, ",")) !== false) {
            $first = ltrim(substr($name, $comma + 1));
            if (!preg_match('{\A(?:' . self::SUFFIX_REGEX . ')\z}i', $first)) {
                $ret[0] = $first . $paren;
                $ret[1] = trim(substr($name, 0, $comma)) . $suffix;
                return $ret;
            }
            $suffix = substr($name, $comma) . $suffix . $paren;
            $paren = "";
            $name = rtrim(substr($name, 0, $comma));
        }

        if (preg_match('{[^\s,]+(?:\s+(?:' . self::SUFFIX_REGEX . '))?(?:,.*)?\z}i', $name, $m)) {
            $ret[0] = rtrim(substr($name, 0, strlen($name) - strlen($m[0])));
            $ret[1] = ltrim($m[0]) . $suffix . $paren;
            // see also split_von
            if (preg_match('@^(\S.*?)\s+(v[oa]n|d[eu])$@i', $ret[0], $m))
                list($ret[0], $ret[1]) = array($m[1], $m[2] . " " . $ret[1]);
        } else
            $ret[1] = $name . $suffix . $paren;
        return $ret;
    }

    static function split_first_middle($first) {
        if (preg_match('%\A((?:\pL\.\s*)*\pL[^\s.]\S*)\s+(.*)\z%', $first, $m)
            || preg_match('%\A(\pL[^\s.]\S*)\s*(.*)\z%', $first, $m))
            return [$m[1], $m[2]];
        else
            return [$first, ""];
    }

    static function split_last_suffix($last) {
        if (preg_match('{\A(.*?\S)(?:\s+|\s*,\s*)(' . self::SUFFIX_REGEX . ')\z}i', $last, $m)) {
            if (preg_match('{\A(?:jr|sr|esq)\z}i', $m[2]))
                $m[2] .= ".";
            return [$m[1], $m[2]];
        } else
            return [$last, ""];
    }

    static function unaccented_name(/* ... */) {
        $x = self::analyze_name_args(func_get_args());
        return $x->unaccentedName;
    }

    static function word_regex($word) {
        if ($word === "")
            return "";
        list($aw, $zw) = array(ctype_alnum($word[0]),
                               ctype_alnum($word[strlen($word) - 1]));
        return ($aw ? '\b' : '')
            . str_replace(" ", '\s+', $word)
            . ($zw ? '\b' : '');
    }

    static function star_text_pregexes($word) {
        if (is_object($word))
            $reg = $word;
        else
            $reg = (object) ["value" => $word];

        $word = preg_quote(preg_replace('/\s+/', " ", $reg->value));
        if (strpos($word, "*") !== false) {
            $word = str_replace('\*', '\S*', $word);
            $word = str_replace('\\\\\S*', '\*', $word);
        }

        if (preg_match("/[\x80-\xFF]/", $word))
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        else {
            $reg->preg_raw = Text::word_regex($word);
            $reg->preg_utf8 = Text::utf8_word_regex($word);
        }
        return $reg;
    }

    static function match_pregexes($reg, $text, $deaccented_text) {
        if (!isset($reg->preg_raw))
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $text);
        else if ($deaccented_text)
            return !!preg_match('{' . $reg->preg_utf8 . '}ui', $deaccented_text);
        else
            return !!preg_match('{' . $reg->preg_raw . '}i', $text);
    }


    const UTF8_INITIAL_NONLETTER = '(?:\A|(?!\pL|\pN)\X)';

    static function utf8_word_regex($word) {
        if ($word === "")
            return "";
        list($aw, $zw) = array(preg_match('{\A(?:\pL|\pN)}u', $word),
                               preg_match('{(?:\pL|\pN)\z}u', $word));
        return ($aw ? self::UTF8_INITIAL_NONLETTER : '')
            . str_replace(" ", '(?:\s|\p{Zs})+', $word)
            . ($zw ? '(?:\z|(?!\pL|\pN)(?=\PM))' : '');
    }

    static function highlight($text, $match, &$n = null) {
        $n = 0;
        if ($match === null || $match === false || $match === "" || $text == "")
            return htmlspecialchars($text);

        $mtext = $text;
        $offsetmap = null;
        $flags = "";
        if (is_object($match)) {
            if (!isset($match->preg_raw)) {
                $match = $match->preg_utf8;
                $flags = "u";
            } else if (preg_match('/[\x80-\xFF]/', $text)) {
                list($mtext, $offsetmap) = UnicodeHelper::deaccent_offsets($mtext);
                $match = $match->preg_utf8;
                $flags = "u";
            } else
                $match = $match->preg_raw;
        }

        $s = $clean_initial_nonletter = false;
        if ($match !== null && $match !== "") {
            if (str_starts_with($match, self::UTF8_INITIAL_NONLETTER))
                $clean_initial_nonletter = true;
            if ($match[0] !== "{")
                $match = "{(" . $match . ")}is" . $flags;
            $s = preg_split($match, $mtext, -1, PREG_SPLIT_DELIM_CAPTURE);
        }
        if (!$s || count($s) == 1)
            return htmlspecialchars($text);

        $n = (int) (count($s) / 2);
        if ($offsetmap)
            for ($i = $b = $o = 0; $i < count($s); ++$i)
                if ($s[$i] !== "") {
                    $o += strlen($s[$i]);
                    $e = UnicodeHelper::deaccent_translate_offset($offsetmap, $o);
                    $s[$i] = substr($text, $b, $e - $b);
                    $b = $e;
                }
        if ($clean_initial_nonletter)
            for ($i = 1; $i < count($s); $i += 2)
                if ($s[$i] !== ""
                    && preg_match('{\A((?!\pL|\pN)\X)(.*)\z}us', $s[$i], $m)) {
                    $s[$i - 1] .= $m[1];
                    $s[$i] = $m[2];
                }
        for ($i = 0; $i < count($s); ++$i)
            if (($i % 2) && $s[$i] !== "")
                $s[$i] = '<span class="match">' . htmlspecialchars($s[$i]) . "</span>";
            else
                $s[$i] = htmlspecialchars($s[$i]);
        return join("", $s);
    }

    const SEARCH_CASE_SENSITIVE = 1;
    const SEARCH_UNPRIVILEGE_EXACT = 2;
    const SEARCH_ONLY_EXACT = 4;

    static function simple_search($needle, $haystacks, $flags = 0) {
        $reflags = $flags & self::SEARCH_CASE_SENSITIVE ? "" : "i";
        $rewords = array();
        foreach (preg_split('/[^A-Za-z_0-9*]+/', $needle) as $word)
            if ($word !== "")
                $rewords[] = str_replace("*", ".*", $word);
        $matches = array();
        $i = $flags & self::SEARCH_UNPRIVILEGE_EXACT ? 1 : 0;
        $last = $flags & self::SEARCH_ONLY_EXACT ? $i : 2;
        for (; $i <= $last && !count($matches); ++$i) {
            if ($i == 0)
                $re = ',\A' . join('\b.*\b', $rewords) . '\z,' . $reflags;
            else if ($i == 1)
                $re = ',\A' . join('\b.*\b', $rewords) . '\b,' . $reflags;
            else
                $re = ',\b' . join('.*\b', $rewords) . ',' . $reflags;
            $matches = preg_grep($re, $haystacks);
        }
        return $matches;
    }

    static function is_boring_word($word) {
        return isset(self::$boring_words[strtolower($word)]);
    }

    static function single_line_paragraphs($text) {
        preg_match_all('/.*?(?:\r\n?|\n|\z)/', $text, $m);
        $out = "";
        $last = false;
        foreach ($m[0] as $line) {
            if ($line !== "" && $last && !ctype_space(substr($line, 0, 1)))
                $out = rtrim($out) . " " . $line;
            else
                $out .= $line;
            $last = strlen($line) > 50;
        }
        return $out;
    }
}

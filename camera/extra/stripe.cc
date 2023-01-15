#include <math.h>
#include <vector>
#include <stdint.h>
#include <assert.h>
#include <unistd.h>
#include <string>
#include <stdlib.h>
#include <stdio.h>
#include <iostream>
#include <sstream>
#include <map>

class Rgba {
  public:
    uint8_t r;
    uint8_t g;
    uint8_t b;
    double a;
};

class Stripe {
  public:
    Stripe(double angle);
    ~Stripe();

    void add(uint8_t r, uint8_t g, uint8_t b, double a, int width);
    void add(const Rgba& pixel, int width) {
        add(pixel.r, pixel.g, pixel.b, pixel.a, width);
    }

    void output_ppm(FILE* f);
    void output_alpha(FILE* f);
    void output_png(FILE* f);
    void output_png(const char* fname);
    std::string svg();
    std::string svg_base64();

  private:
    double angle_;
    struct onestripe {
        double width;
        double color[4];
        std::string fill() const;
    };
    std::vector<onestripe> stripes_;

    int width_;
    int height_;
    bool rot90_;

    struct Pixel {
        uint8_t c[4];
    };
    Pixel** p_;

    void analyze();
    bool rest_fully_opaque() const;
};

Stripe::Stripe(double angle)
    : angle_(angle), p_(nullptr) {
}

Stripe::~Stripe() {
    if (p_) {
        for (unsigned i = 0; i != height_; ++i)
            delete[] p_[i];
        delete[] p_;
    }
}

void Stripe::add(uint8_t r, uint8_t g, uint8_t b, double a, int width) {
    stripes_.push_back(onestripe());
    stripes_.back().width = width;
    stripes_.back().color[0] = r / 255.0;
    stripes_.back().color[1] = g / 255.0;
    stripes_.back().color[2] = b / 255.0;
    stripes_.back().color[3] = a;
}

std::string Stripe::onestripe::fill() const {
    char buf[64];
    unsigned r = (unsigned) (color[0] * 255.0 + 0.5),
        g = (unsigned) (color[1] * 255.0 + 0.5),
        b = (unsigned) (color[2] * 255.0 + 0.5);
    if (color[3] != 1)
        sprintf(buf, "rgba(%u,%u,%u,%g)", r, g, b, color[3]);
    else if (r / 16 == r % 16 && g / 16 == g % 16 && b / 16 == b % 16)
        sprintf(buf, "#%x%x%x", r / 16, g / 16, b / 16);
    else
        sprintf(buf, "#%02x%02x%02x", r, g, b);
    return buf;
}

void Stripe::analyze() {
    assert(!p_);
    if (angle_ < 0)
        angle_ -= M_PI * floor(angle_ / M_PI);
    if (angle_ >= M_PI)
        angle_ = fmod(angle_, M_PI);
    if (angle_ == 0) {
        angle_ = M_PI / 2;
        rot90_ = true;
    } else if (angle_ > M_PI / 2) {
        angle_ -= M_PI / 2;
        rot90_ = true;
    } else
        rot90_ = false;

    double totalw = 0;
    for (auto& s : stripes_)
        totalw += s.width / sin(angle_);

    width_ = (int) ceil(totalw);
    height_ = (int) ceil(width_ * tan(angle_));
    angle_ = atan2(height_, width_);

    for (auto& s : stripes_)
        s.width += (width_ - totalw) * sin(angle_) / stripes_.size();

    // actually draw
    p_ = new Pixel*[height_];
    for (unsigned i = 0; i != height_; ++i)
        p_[i] = new Pixel[width_];

    // positions of stripes
    unsigned ns = stripes_.size();
    double* xp = new double[2 * ns];
    xp[0] = -width_;
    xp[ns] = 0;
    for (unsigned i = 1; i != ns; ++i) {
        double w = stripes_[i].width / sin(angle_);
        xp[i] = xp[i-1] + w;
        xp[i+ns] = xp[i-1+ns] + w;
    }
    // deltas
    double* delta = new double[2 * ns];
    for (unsigned i = 0; i != 2 * ns; ++i)
        delta[i] = 1 / tan(angle_);
    double* amt = new double[2 * ns];

    // walk over pixels
    for (unsigned y = 0; y != height_; ++y) {
        for (unsigned x = 0; x != width_; ++x) {
            // check amounts
            amt[0] = 1;
            for (unsigned i = 1; i != 2 * ns; ++i) {
                double bl = xp[i], tl = xp[i] + delta[i], myamt;
                if (bl >= x + 1)
                    myamt = 0;
                else if (bl <= x && tl <= x)
                    myamt = 1;
                else if (bl <= x && tl >= x + 1)
                    myamt = tan(angle_) * (x + 0.5 - bl);
                else if (bl <= x)
                    myamt = 1 - 0.5 * (tl - x) * (tl - x) / delta[i];
                else if (tl <= x + 1)
                    myamt = (tl + bl) / 2 - x;
                else
                    myamt = 0.5 * (x + 1 - bl) * (x + 1 - bl) / delta[i];
                amt[i - 1] -= myamt;
                amt[i] = myamt;
            }
#if 0
            std::cerr << x << " " << y << " ";
            for (unsigned i = 0; i != 2 * ns; ++i)
                std::cerr << "[" << xp[i] << " " << amt[i] << "] ";
            std::cerr << "\n";
#endif

            // combine pixels
            double c[4] = {0, 0, 0, 0};
            for (unsigned i = 0; i != 2 * ns; ++i)
                if (amt[i]) {
                    auto& s = stripes_[i % ns];
                    for (int j = 0; j != 4; ++j)
                        c[j] += s.color[j] * amt[i];
                }

            // set pixel
            auto& p = p_[y][x];
            for (int j = 0; j != 4; ++j)
                p.c[j] = (unsigned) round(c[j] * 255);
        }

        // increment x positions
        for (unsigned i = 0; i != 2 * ns; ++i)
            xp[i] += delta[i];
    }

    delete[] xp;
    delete[] delta;
    delete[] amt;
}

void Stripe::output_alpha(FILE* f) {
    assert(f);
    if (!p_)
        analyze();

    fprintf(f, "P7\nWIDTH %u\nHEIGHT %u\nDEPTH 1\nMAXVAL 255\nTUPLTYPE GRAYSCALE\nENDHDR\n", width_, height_);
    if (rot90_) {
        for (int x = width_ - 1; x >= 0; --x)
            for (int y = height_ - 1; y >= 0; --y)
                fwrite(&p_[y][x].c[3], 1, 1, f);
    } else {
        for (int y = height_ - 1; y >= 0; --y)
            for (int x = 0; x != width_; ++x)
                fwrite(&p_[y][x].c[3], 1, 1, f);
    }
}

void Stripe::output_ppm(FILE* f) {
    assert(f);
    if (!p_)
        analyze();

    fprintf(f, "P7\nWIDTH %u\nHEIGHT %u\nDEPTH 3\nMAXVAL 255\nTUPLTYPE RGB\nENDHDR\n", width_, height_);
    if (rot90_) {
        for (int x = width_ - 1; x >= 0; --x)
            for (int y = height_ - 1; y >= 0; --y)
                fwrite(&p_[y][x].c[0], 1, 3, f);
    } else {
        for (int y = height_ - 1; y >= 0; --y)
            for (int x = 0; x != width_; ++x)
                fwrite(&p_[y][x].c[0], 1, 3, f);
    }
}

void Stripe::output_png(FILE* f) {
    char rgb[] = "/tmp/stripe.rgb.XXXXXX";
    char alpha[] = "/tmp/stripe.alpha.XXXXXX";
    int rgbfd = mkstemp(rgb);
    int alphafd = mkstemp(alpha);
    if (rgbfd >= 0 && alphafd >= 0) {
        FILE* rgbf = fdopen(rgbfd, "wb");
        output_ppm(rgbf);
        fclose(rgbf);
        FILE* alphaf = fdopen(alphafd, "wb");
        output_alpha(alphaf);
        fclose(alphaf);
        std::string cmd = "pnmtopng -interlace -alpha=" + std::string(alpha) + " " + std::string(rgb);
        std::cerr << cmd << "\n";
        FILE* p = popen(cmd.c_str(), "r");
        char buf[BUFSIZ];
        ssize_t r;
        while ((r = fread(buf, 1, BUFSIZ, p)) != 0)
            fwrite(buf, 1, r, f);
        pclose(p);
    }
    if (rgbfd >= 0)
        unlink(rgb);
    if (alphafd >= 0)
        unlink(alpha);
}

void Stripe::output_png(const char* fname) {
    FILE* f = fopen(fname, "wb");
    assert(f);
    output_png(f);
    fclose(f);
}

bool Stripe::rest_fully_opaque() const {
    for (unsigned i = 1; i < stripes_.size(); ++i)
        if (stripes_[i].color[3] != 1)
            return false;
    return true;
}

double fix(double x) {
    return round(x * 1024.) / 1024.;
}

std::string Stripe::svg() {
    if (!p_)
        analyze();
    std::ostringstream buf;
    buf << "<svg xmlns=\"http://www.w3.org/2000/svg\" "
        << "width=\"" << (rot90_ ? height_ : width_) << "\" "
        << "height=\"" << (rot90_ ? width_ : height_) << "\">";
    double pos = -width_;
    for (unsigned i = 0; i != stripes_.size(); ++i) {
        auto& s = stripes_[i];
        if (s.color[3] && i == 0 && rest_fully_opaque())
            buf << "<rect x=\"0\" y=\"0\" width=\"100%\" height=\"100%\" fill=\"" << s.fill() << "\" />";
        else if (s.color[3]) {
            buf << "<polyline points=\""
                << fix(pos) << ',' << height_ << ','
                << fix(pos + height_ / tan(angle_)) << ",0,"
                << fix(pos + s.width / sin(angle_) + height_ / tan(angle_)) << ",0,"
                << fix(pos + s.width / sin(angle_)) << ',' << height_
                << "\" fill=\"" << s.fill() << "\" />";
            buf << "<polyline points=\""
                << fix(pos + width_) << ',' << height_ << ','
                << fix(pos + width_ + height_ / tan(angle_)) << ",0,"
                << fix(pos + width_ + s.width / sin(angle_) + height_ / tan(angle_)) << ",0,"
                << fix(pos + width_ + s.width / sin(angle_)) << ',' << height_
                << "\" fill=\"" << s.fill() << "\" />";
        }
        pos += s.width / sin(angle_);
    }
    buf << "</svg>";
    return buf.str();
}

static const char base64_encoding_table[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

std::string base64(std::string x, bool pad = true) {
    std::ostringstream buf;
    const unsigned char* s = reinterpret_cast<const unsigned char*>(x.data()),
        *end = s + x.length();
    for (; end - s >= 3; s += 3) {
        unsigned x = (s[0] << 16) | (s[1] << 8) | s[2];
        buf << base64_encoding_table[x >> 18]
            << base64_encoding_table[(x >> 12) & 63]
            << base64_encoding_table[(x >> 6) & 63]
            << base64_encoding_table[x & 63];
    }
    if (end > s) {
        unsigned x = s[0] << 16;
        if (end > s + 1)
            x |= s[1] << 8;
        buf << base64_encoding_table[x >> 18]
            << base64_encoding_table[(x >> 12) & 63];
        if (end > s + 1)
            buf << base64_encoding_table[(x >> 6) & 63];
        else if (pad)
            buf << '=';
        if (pad)
            buf << '=';
    }
    return buf.str();
}

std::string Stripe::svg_base64() {
    return base64(svg());
}

std::map<std::string, Rgba> colormap;

void makeit(const char* name1, const char* name2) {
    std::string fn = "images/tag_" + std::string(name1) + "_" + std::string(name2) + ".png";
    Stripe s(M_PI / 4);
    s.add(colormap[name1], 12);
    s.add(colormap[name2], 12);
//    s.output_png(fn.c_str());
    fprintf(stdout, "%s\n", s.svg_base64().c_str());
}

int main() {
    const char* names[] = {"red", "orange", "yellow", "green", "blue", "purple", "gray", "white", nullptr};

    colormap["red"] = Rgba{0xff, 0xd8, 0xd8, 1};
    colormap["reddark"] = Rgba{0xf8, 0xd0, 0xd0, 1};
    colormap["orange"] = Rgba{0xfd, 0xeb, 0xcc, 1};
    colormap["orangedark"] = Rgba{0xf2, 0xde, 0xbb, 1};
    colormap["yellow"] = Rgba{0xfd, 0xff, 0xcb, 1};
    colormap["yellowdark"] = Rgba{0xf0, 0xee, 0xb4, 1};
    colormap["green"] = Rgba{0xd8, 0xff, 0xd8, 1};
    colormap["greendark"] = Rgba{0xc4, 0xf2, 0xc4, 1};
    colormap["blue"] = Rgba{0xd8, 0xd8, 0xff, 1};
    colormap["bluedark"] = Rgba{0xcc, 0xcc, 0xf2, 1};
    colormap["purple"] = Rgba{0xf2, 0xd8, 0xf8, 1};
    colormap["purpledark"] = Rgba{0xea, 0xc7, 0xf2, 1};
    colormap["gray"] = Rgba{0xe2, 0xe2, 0xe2, 1};
    colormap["graydark"] = Rgba{0xd9, 0xd9, 0xd9, 1};
    colormap["white"] = Rgba{0xff, 0xff, 0xff, 1};
    colormap["whitedark"] = Rgba{0xf8, 0xf8, 0xf8, 1};
    colormap["transparent"] = Rgba{0, 0, 0, 0};

    /*makeit("gray", "transparent");
    for (const char** name1 = names; *name1; ++name1)
        for (const char** name2 = name1 + 1; *name2; ++name2)
            makeit(*name1, *name2);*/

    {
        Stripe s(M_PI / 4);
        s.add(Rgba{0xff, 0xff, 0, 1}, 2);
        s.add(Rgba{105, 105, 3, 1}, 2);
        fprintf(stdout, "%s\n", s.svg().c_str());
        fprintf(stdout, "%s\n", s.svg_base64().c_str());
    }

    {
        Stripe s(M_PI / 4);
        s.add(Rgba{0xff, 0, 0, 1}, 4);
        s.add(Rgba{0x40, 0, 0, 1}, 4);
        fprintf(stdout, "%s\n", s.svg().c_str());
        fprintf(stdout, "%s\n", s.svg_base64().c_str());
    }

    {
        Stripe s(M_PI / 4);
        s.add(Rgba{0xce, 0xc1, 0xff, 1}, 2);
        s.add(Rgba{0x99, 0x99, 0xbf, 1}, 1);
        fprintf(stdout, "%s\n", s.svg().c_str());
        fprintf(stdout, "%s\n", s.svg_base64().c_str());
    }
}

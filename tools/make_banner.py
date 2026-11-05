#!/usr/bin/env python3
"""Generate the ToolsByDcx welcome banner: static PNG + ANIMATED GIF
(animated coloured dots + pulsing LIVE badge, like the original banner)."""
import math, os, random
from PIL import Image, ImageDraw, ImageFont

W, H = 1280, 640
OUTDIR = os.path.expanduser("~/workspace/clone_telegram_tools/public/assets")
os.makedirs(OUTDIR, exist_ok=True)

def font(size, bold=True):
    p = ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf" if bold
         else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
    return ImageFont.truetype(p, size)

def frame(phase):
    img = Image.new("RGB", (W, H), (10, 8, 32))
    d = ImageDraw.Draw(img, "RGBA")
    for y in range(H):
        t = y / H
        d.line([(0, y), (W, y)], fill=(int(10 + 40 * t), int(8 + 20 * t), int(32 + 110 * t)))
    random.seed(7)
    # ambient glow dots, drifting with phase
    for k in range(60):
        x0, y0 = random.randint(0, W), random.randint(0, H)
        r = random.randint(2, 7)
        col = random.choice([(0, 210, 255), (180, 80, 255), (255, 80, 200), (80, 255, 180)])
        x = (x0 + int(14 * math.sin(phase + k))) % W
        y = (y0 + int(10 * math.cos(phase * 0.7 + k))) % H
        d.ellipse([x - r, y - r, x + r, y + r], fill=col + (200,))
    # animated coloured dots row (wave travelling left to right)
    for i in range(24):
        x = 90 + i * 48
        y = 120 + int(18 * math.sin(i * 0.9 + phase * 2))
        col = [(0, 210, 255), (180, 80, 255), (255, 80, 200), (255, 190, 0)][i % 4]
        d.ellipse([x - 9, y - 9, x + 9, y + 9], fill=col)
    # pulsing LIVE badge
    pulse = int(200 + 55 * math.sin(phase * 2))
    d.rounded_rectangle([1010, 60, 1190, 110], radius=25, fill=(pulse, 40, 60))
    d.text((1100, 68), "● LIVE", font=font(34), fill=(255, 255, 255), anchor="ma")
    d.text((W // 2, 210), "WELCOME TO", font=font(72), fill=(200, 220, 255), anchor="ma")
    d.text((W // 2, 300), "TOOLSBYDCX", font=font(120), fill=(255, 255, 255), anchor="ma",
           stroke_width=2, stroke_fill=(0, 210, 255))
    d.rounded_rectangle([440, 460, 840, 472], radius=6, fill=(0, 210, 255))
    d.text((W // 2, 500), "AI TOOLS  •  ACCOUNTS  •  AUTO DELIVERY", font=font(34),
           fill=(0, 210, 255), anchor="ma")
    return img

N = 10
frames = [frame(2 * math.pi * i / N) for i in range(N)]
frames[0].save(os.path.join(OUTDIR, "banner.gif"), save_all=True,
               append_images=frames[1:], duration=150, loop=0, optimize=True)
frames[0].save(os.path.join(OUTDIR, "banner.png"))
print("banner.png + banner.gif done",
      os.path.getsize(os.path.join(OUTDIR, "banner.gif")), "bytes gif")

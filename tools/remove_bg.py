#!/usr/bin/env python3
"""
Remove photo background for AVBA credential cards.
Usage: python3 remove_bg.py <input> <output> [navy|white]
"""
import sys
import os
import io

def create_navy_gradient(w, h):
    """Dark navy gradient matching credential card palette."""
    try:
        import numpy as np
        from PIL import Image
        arr = __import__('numpy').zeros((h, w, 4), dtype='uint8')
        for y in range(h):
            t = y / max(h - 1, 1)
            arr[y, :, 0] = int(10  + (5  - 10)  * t)  # R: 10→5
            arr[y, :, 1] = int(36  + (14 - 36)  * t)  # G: 36→14
            arr[y, :, 2] = int(68  + (28 - 68)  * t)  # B: 68→28
            arr[y, :, 3] = 255
        return Image.fromarray(arr, 'RGBA')
    except ImportError:
        from PIL import Image
        bg = Image.new('RGBA', (w, h), (10, 36, 68, 255))
        return bg


def main():
    if len(sys.argv) < 3:
        print("Usage: remove_bg.py <input> <output> [navy|white]", file=sys.stderr)
        sys.exit(1)

    input_path  = sys.argv[1]
    output_path = sys.argv[2]
    bg_type     = sys.argv[3] if len(sys.argv) > 3 else 'navy'

    try:
        from rembg import remove
        from PIL import Image
    except ImportError as e:
        print(f"ERROR: missing dependency — {e}", file=sys.stderr)
        sys.exit(1)

    if not os.path.exists(input_path):
        print(f"ERROR: input not found: {input_path}", file=sys.stderr)
        sys.exit(1)

    with open(input_path, 'rb') as fh:
        raw = fh.read()

    result_bytes = remove(raw)
    fg = Image.open(io.BytesIO(result_bytes)).convert('RGBA')
    w, h = fg.size

    if bg_type == 'white':
        bg = Image.new('RGBA', (w, h), (255, 255, 255, 255))
    else:
        bg = create_navy_gradient(w, h)

    bg.paste(fg, (0, 0), fg)

    out_dir = os.path.dirname(output_path)
    if out_dir and not os.path.isdir(out_dir):
        os.makedirs(out_dir, exist_ok=True)

    ext = os.path.splitext(output_path)[1].lower()
    if ext in ('.jpg', '.jpeg'):
        bg.convert('RGB').save(output_path, 'JPEG', quality=92)
    else:
        bg.save(output_path, 'PNG')

    print(f"OK: {output_path}")


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""
Reads JSON on stdin: { "smiles": "<text>", "out_path": "/abs/path/file.png", "size": [w,h] (optional) }
Writes JSON: { "ok": true, "via": "rdkit"|"obabel"|"text"|"placeholder" } on success
and ensures out_path is a PNG file on disk.

Strategy:
- RDKit if available → 2D depiction to PNG
- else Open Babel (obabel) → --gen2d PNG
- else render a text-based fallback (Pillow) so we still return a meaningful image
- else write a tiny transparent PNG placeholder
"""

import json, sys, os, shutil, subprocess, textwrap, math

try:
    from PIL import Image, ImageDraw, ImageFont
except Exception:  # Pillow optional; fallback logic will detect availability
    Image = ImageDraw = ImageFont = None

try:
    import networkx as nx  # type: ignore
except Exception:  # networkx optional; fallback logic will detect availability
    nx = None

PLACEHOLDER_PNG = (
    b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01"
    b"\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0cIDATx\x9cc``\x00"
    b"\x00\x00\x02\x00\x01\xe2!\xbc3\x00\x00\x00\x00IEND\xaeB`\x82"
)

def read_input():
    raw = sys.stdin.read()
    data = json.loads(raw)
    s = (data.get("smiles") or "").strip()
    out = data.get("out_path")
    size = data.get("size") or [320, 240]
    if not isinstance(s, str) or not isinstance(out, str):
        raise ValueError("smiles and out_path are required")
    if not isinstance(size, (list, tuple)) or len(size) != 2:
        size = [320, 240]
    w, h = int(size[0]), int(size[1])
    return s, out, (w, h)

def try_rdkit(smiles: str, out_path: str, size):
    try:
        from rdkit import Chem
        from rdkit.Chem import Draw
        mol = Chem.MolFromSmiles(smiles)
        if mol is None:
            return {"ok": False, "message": "invalid SMILES"}
        img = Draw.MolToImage(mol, size=size)
        img.save(out_path, format="PNG")
        return {"ok": True, "via": "rdkit"}
    except Exception:
        return None  # unavailable or failed

def _run(args, timeout=6):
    try:
        p = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
        return p.returncode, p.stdout, p.stderr
    except subprocess.TimeoutExpired:
        return 124, "", "timeout"

def try_obabel(smiles: str, out_path: str):
    if not shutil.which("obabel"):
        return None
    rc, _, err = _run(["obabel", f"-:{smiles}", "-O", out_path, "--gen2d"])
    if rc == 0 and os.path.exists(out_path) and os.path.getsize(out_path) > 0:
        return {"ok": True, "via": "obabel"}
    # consider invalid if obabel rejected it
    return {"ok": False, "message": "invalid SMILES (obabel)"}

def try_text_render(smiles: str, out_path: str, size):
    if Image is None or ImageDraw is None or ImageFont is None:
        return None
    try:
        width, height = size
    except Exception:
        width, height = 320, 240
    width = max(int(width or 0), 320)
    height = max(int(height or 0), 240)

    img = Image.new("RGB", (width, height), "white")
    draw = ImageDraw.Draw(img)
    font = ImageFont.load_default()

    max_chars = max(8, min(48, width // 8))
    wrapped = textwrap.wrap(smiles, width=max_chars) or [smiles]
    text = "\n".join(wrapped[:12])  # cap lines to avoid runaway rendering

    spacing = 4
    bbox_fn = getattr(draw, "multiline_textbbox", None)
    if callable(bbox_fn):
        left, top, right, bottom = bbox_fn((0, 0), text, font=font, spacing=spacing, align="center")
        text_w = right - left
        text_h = bottom - top
    else:
        text_w, text_h = draw.multiline_textsize(text, font=font, spacing=spacing)

    pad = 12
    x = max(pad, (width - text_w) // 2)
    y = max(pad, (height - text_h) // 2)

    # Draw a subtle frame for readability
    frame_margin = 6
    draw.rectangle([(0, 0), (width - 1, height - 1)], outline=(0, 0, 0))
    draw.rectangle(
        [(frame_margin, frame_margin), (width - frame_margin - 1, height - frame_margin - 1)],
        fill=(255, 255, 255),
        outline=None
    )
    draw.multiline_text((x, y), text, fill=(0, 0, 0), font=font, spacing=spacing, align="center")

    img.save(out_path, format="PNG")
    return {"ok": True, "via": "text"}


AROMATIC_ATOMS = {'b', 'c', 'n', 'o', 'p', 's'}
TWO_LETTER_ELEMENTS = {
    'Cl', 'Br', 'Si', 'Li', 'Na', 'Mg', 'Ca', 'Fe', 'Cu', 'Zn', 'Hg',
    'Al', 'Sn', 'Pb', 'Ag', 'Au', 'Ni', 'Co', 'Mn', 'Ti', 'Cr', 'Pt'
}


def parse_smiles_graph(smiles: str):
    """Very small SMILES parser that builds an undirected graph. Supports common syntax."""
    if nx is None:
        return None

    atoms = []
    edges = []
    stack = []
    ring_bonds = {}
    prev = None
    bond_order = 1
    i = 0
    length = len(smiles)

    def add_atom(label: str, aromatic: bool):
        nonlocal prev, bond_order
        idx = len(atoms)
        atoms.append({'label': label, 'aromatic': aromatic})
        if prev is not None:
            edges.append({'u': prev, 'v': idx, 'order': bond_order})
        prev = idx
        bond_order = 1

    while i < length:
        ch = smiles[i]

        if ch in '-=#':
            bond_order = {'-': 1, '=': 2, '#': 3}[ch]
            i += 1
            continue

        if ch == '(':
            stack.append(prev)
            i += 1
            continue

        if ch == ')':
            prev = stack.pop() if stack else prev
            i += 1
            continue

        if ch == '[':
            j = i + 1
            bracket_content = []
            while j < length and smiles[j] != ']':
                bracket_content.append(smiles[j])
                j += 1
            if j >= length:
                raise ValueError("Unmatched '[' in SMILES")
            token = ''.join(bracket_content).strip() or 'C'
            label = token
            aromatic = token.islower()
            add_atom(label, aromatic)
            i = j + 1
            continue

        if ch == '%':
            if i + 2 >= length or not smiles[i + 1:i + 3].isdigit():
                raise ValueError("Invalid ring index")
            ring_id = smiles[i + 1:i + 3]
            i += 3
        elif ch.isdigit():
            ring_id = ch
            i += 1
        else:
            ring_id = None

        if ring_id is not None:
            if ring_id not in ring_bonds:
                ring_bonds[ring_id] = (prev, bond_order)
            else:
                other_prev, stored_order = ring_bonds.pop(ring_id)
                order = bond_order if bond_order != 1 else stored_order
                if prev is not None and other_prev is not None:
                    edges.append({'u': other_prev, 'v': prev, 'order': order})
            bond_order = 1
            continue

        # Atom parsing
        if ch.isalpha():
            next_two = smiles[i:i + 2]
            if next_two in TWO_LETTER_ELEMENTS:
                label = next_two
                i += 2
            else:
                label = ch
                i += 1
            aromatic = label.islower() or label in AROMATIC_ATOMS
            add_atom(label.capitalize(), aromatic)
            continue

        if ch == '.':
            prev = None
            bond_order = 1
            i += 1
            continue

        # Unsupported token; bail back to caller
        raise ValueError(f"Unsupported SMILES token '{ch}'")

    if ring_bonds:
        raise ValueError("Unclosed ring bonds")

    if not atoms:
        return None

    G = nx.Graph()
    for idx, atom in enumerate(atoms):
        G.add_node(idx, label=atom['label'], aromatic=atom['aromatic'])
    for edge in edges:
        G.add_edge(edge['u'], edge['v'], order=edge['order'])
    return G


def try_graph_render(smiles: str, out_path: str, size):
    if Image is None or ImageDraw is None or ImageFont is None or nx is None:
        return None
    try:
        graph = parse_smiles_graph(smiles)
    except Exception:
        return None
    if graph is None or graph.number_of_nodes() == 0:
        return None

    width, height = size
    width = max(int(width or 0), 320)
    height = max(int(height or 0), 240)

    if graph.number_of_nodes() == 1:
        pos = {list(graph.nodes())[0]: (0.5, 0.5)}
    else:
        pos = nx.spring_layout(graph, seed=42, iterations=200)

    xs = [coord[0] for coord in pos.values()]
    ys = [coord[1] for coord in pos.values()]
    min_x, max_x = min(xs), max(xs)
    min_y, max_y = min(ys), max(ys)
    span_x = max(max_x - min_x, 1e-3)
    span_y = max(max_y - min_y, 1e-3)

    margin = 40
    def scale(p):
        x = margin + ( (p[0] - min_x) / span_x ) * (width - 2 * margin)
        y = margin + ( (p[1] - min_y) / span_y ) * (height - 2 * margin)
        return x, y

    coords = {node: scale(pos[node]) for node in graph.nodes()}

    img = Image.new("RGB", (width, height), "white")
    draw = ImageDraw.Draw(img)
    font = ImageFont.load_default()

    def draw_bond(p1, p2, order):
        if order <= 0:
            order = 1
        dx = p2[0] - p1[0]
        dy = p2[1] - p1[1]
        length = math.hypot(dx, dy)
        if length == 0:
            return
        ux = dx / length
        uy = dy / length
        px = -uy
        py = ux
        spacing = 6
        count = max(1, min(order, 3))
        for n in range(count):
            offset = (n - (count - 1) / 2) * spacing
            start = (p1[0] + px * offset, p1[1] + py * offset)
            end = (p2[0] + px * offset, p2[1] + py * offset)
            draw.line([start, end], fill=(0, 0, 0), width=3)

    for u, v, data in graph.edges(data=True):
        draw_bond(coords[u], coords[v], data.get('order', 1))

    def text_dimensions(text):
        if hasattr(draw, "textbbox"):
            bbox = draw.textbbox((0, 0), text, font=font)
            return bbox[2] - bbox[0], bbox[3] - bbox[1]
        if hasattr(font, "getsize"):
            return font.getsize(text)
        return len(text) * 6, 10

    for node, data in graph.nodes(data=True):
        x, y = coords[node]
        label = data.get('label') or 'C'
        tw, th = text_dimensions(label)
        draw.ellipse((x - 12, y - 12, x + 12, y + 12), fill=(255, 255, 255), outline=(0, 0, 0))
        draw.text((x - tw / 2, y - th / 2), label, fill=(0, 0, 0), font=font)

    img.save(out_path, format="PNG")
    return {"ok": True, "via": "graph"}

def write_placeholder(out_path: str):
    try:
        with open(out_path, "wb") as f:
            f.write(PLACEHOLDER_PNG)
        return {"ok": True, "via": "placeholder"}
    except Exception as e:
        return {"ok": False, "message": f"placeholder write failed: {e}"}

def main():
    try:
        smiles, out_path, size = read_input()
        os.makedirs(os.path.dirname(out_path), exist_ok=True)

        rd = try_rdkit(smiles, out_path, size)
        if isinstance(rd, dict):
            print(json.dumps(rd)); return

        ob = try_obabel(smiles, out_path)
        if isinstance(ob, dict):
            # If obabel said invalid, still produce a placeholder
            if not ob.get("ok"):
                ph = write_placeholder(out_path)
                if ph.get("ok"):
                    print(json.dumps(ph))
                else:
                    print(json.dumps(ob))
                return
            print(json.dumps(ob)); return

        graph_img = try_graph_render(smiles, out_path, size)
        if isinstance(graph_img, dict):
            print(json.dumps(graph_img)); return

        txt = try_text_render(smiles, out_path, size)
        if isinstance(txt, dict):
            print(json.dumps(txt)); return

        # No tool available → placeholder
        ph = write_placeholder(out_path)
        print(json.dumps(ph)); return

    except Exception:
        print(json.dumps({"ok": False, "message": "internal error"}))
        sys.exit(1)

if __name__ == "__main__":
    main()

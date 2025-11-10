#!/usr/bin/env python3
"""
inc/chem_canon.py
Reads JSON on stdin: { "smiles": "<text>" }
Writes JSON on stdout:
  { "ok": true, "canonical": "...", "inchi": "...|null", "inchikey": "...|null", "via": "rdkit"|"obabel"|"noop" }
On invalid (when a toolkit is available and parsing fails): { "ok": false, "message": "invalid SMILES" }
"""

import json, sys, shutil, subprocess

def read_input():
    raw = sys.stdin.read()
    data = json.loads(raw)
    s = data.get("smiles", "")
    if not isinstance(s, str):
        raise ValueError("smiles must be a string")
    return s.strip()

def try_rdkit(smiles: str):
    try:
        from rdkit import Chem
        try:
            from rdkit.Chem.inchi import MolToInchi, MolToInchiKey
        except Exception:
            MolToInchi = MolToInchiKey = None
        mol = Chem.MolFromSmiles(smiles)
        if mol is None:
            return {"ok": False, "message": "invalid SMILES"}
        canonical = Chem.MolToSmiles(mol, canonical=True)
        inchi = inchikey = None
        if MolToInchi:
            try:
                inchi = MolToInchi(mol)
            except Exception:
                inchi = None
        if MolToInchiKey:
            try:
                inchikey = MolToInchiKey(mol)
            except Exception:
                inchikey = None
        return {"ok": True, "canonical": canonical, "inchi": inchi, "inchikey": inchikey, "via": "rdkit"}
    except Exception:
        return None  # RDKit not available

def _run_obabel(args, timeout=4):
    try:
        p = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
        return p.returncode, (p.stdout or "").strip(), (p.stderr or "").strip()
    except subprocess.TimeoutExpired:
        return 124, "", "timeout"

def try_obabel(smiles: str):
    if not shutil.which("obabel"):
        return None
    # Canonical SMILES
    rc, out, err = _run_obabel(["obabel", f"-:{smiles}", "-ocan"])
    if rc != 0 or not out:
        # If Open Babel says it's bad, call it invalid
        return {"ok": False, "message": "invalid SMILES (obabel)"}
    canonical = out.splitlines()[0].strip()

    # InChI & InChIKey (best-effort)
    inchi = inchikey = None
    rc_i, out_i, _ = _run_obabel(["obabel", f"-:{smiles}", "-oinchi"])
    if rc_i == 0 and out_i:
        inchi = out_i.splitlines()[0].strip()
    rc_k, out_k, _ = _run_obabel(["obabel", f"-:{smiles}", "-oinchikey"])
    if rc_k == 0 and out_k:
        inchikey = out_k.splitlines()[0].strip()

    return {"ok": True, "canonical": canonical, "inchi": inchi, "inchikey": inchikey, "via": "obabel"}

def main():
    try:
        smiles = read_input()
        # If nothing installed, we still return a useful response (noop)
        rd = try_rdkit(smiles)
        if isinstance(rd, dict):
            print(json.dumps(rd))
            return
        ob = try_obabel(smiles)
        if isinstance(ob, dict):
            print(json.dumps(ob))
            return
        # Fallback: echo input so frontend can proceed; mark via noop
        print(json.dumps({"ok": True, "canonical": smiles, "inchi": None, "inchikey": None, "via": "noop"}))
    except Exception as e:
        print(json.dumps({"ok": False, "message": "internal error"}))
        sys.exit(1)

if __name__ == "__main__":
    main()


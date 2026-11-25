"""Read saved PDFs and compare expected text. Manifests/results belong outside the repository."""
import argparse
import hashlib
import json
import pathlib
import re
import shutil
import subprocess
import unicodedata
from pypdf import PdfReader

parser = argparse.ArgumentParser()
parser.add_argument("manifest", type=pathlib.Path)
parser.add_argument("--output", required=True, type=pathlib.Path)
args = parser.parse_args()
args.output.mkdir(parents=True, exist_ok=True)

def normalize(value):
    return re.sub(r"\s+", "", unicodedata.normalize("NFKC", str(value)))

results = []
renderer = shutil.which("pdftoppm")
for index, item in enumerate(json.loads(args.manifest.read_text(encoding="utf-8-sig"))):
    path = pathlib.Path(item["path"])
    result = {"path": str(path), "status": "FAIL"}
    try:
        data = path.read_bytes()
        if not data.startswith(b"%PDF-"):
            raise ValueError("Not a PDF")
        pdf = PdfReader(path, strict=True)
        text = "\n".join(page.extract_text() or "" for page in pdf.pages)
        expected = item.get("expectedText", [])
        missing = [token for token in expected if normalize(token) not in normalize(text)]
        digest = hashlib.sha256(data).hexdigest()
        hash_ok = not item.get("expectedSha256") or item["expectedSha256"] == digest
        result.update(size=len(data), pages=len(pdf.pages), sha256=digest, missingText=missing,
                      hashMatches=hash_ok, status="PASS" if expected and not missing and hash_ok and len(pdf.pages) > 0 else "FAIL")
        (args.output / f"pdf-{index+1}.txt").write_text(text, encoding="utf-8")
        if renderer:
            render = subprocess.run([renderer, "-scale-to", "1500", "-png", str(path), str(args.output / f"pdf-{index+1}")], capture_output=True)
            result["rendered"] = render.returncode == 0
            (args.output / f"pdf-{index+1}-render.log").write_bytes(render.stderr)
            if render.returncode != 0: result["status"] = "FAIL"
        else:
            result["rendered"] = False
            result["status"] = "FAIL"
            result["error"] = "pdftoppm is required to verify rendered pages"
    except Exception as error:
        result["error"] = str(error)
    results.append(result)
(args.output / "results.json").write_text(json.dumps(results, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"PDF files: {len(results)}; PASS: {sum(r['status']=='PASS' for r in results)}")
raise SystemExit(0 if all(r["status"] == "PASS" for r in results) else 1)

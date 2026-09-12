#!/usr/bin/env python3
"""نگهداری فایل‌های متادیتا. با .github/workflows/fixups.yml اجرا می‌شود."""

import json
import os
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[2]

# ---- version.json ----
vp = ROOT / "version.json"
data = json.loads(vp.read_text(encoding="utf-8"))
min_php = (os.environ.get("MIN_PHP") or "").strip() or "8.1"
data["min_php"] = min_php
new_build = (os.environ.get("NEW_BUILD") or "").strip()
if new_build:
    data["build"] = new_build
vp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

# ---- README.md ----
rp = ROOT / "README.md"
readme = rp.read_text(encoding="utf-8")
readme = re.sub(
    r"^.*dbdiag\.php.*$",
    "ابزار بررسی سینتکس: `bash tools/lint.sh`",
    readme,
    flags=re.M,
)
if "[LICENSE](LICENSE)" not in readme:
    readme = readme.replace(
        "## 📄 مجوز",
        "## 📄 مجوز\n\nاین سورس رایگان است؛ استفاده، تغییر و بازنشر آن آزاد و **فروش یا بازفروش خودِ سورس ممنوع** است. متن کامل: [LICENSE](LICENSE)",
        1,
    )
rp.write_text(readme, encoding="utf-8")

# ---- admin/pages/dashboard.php ----
dp = ROOT / "admin" / "pages" / "dashboard.php"
if dp.exists():
    dash = dp.read_text(encoding="utf-8")
    if "csrf" not in dash:
        note = (
            "/* این صفحه فقط خواندنی است و هیچ فرم POST ندارد؛ اگر فرمی اضافه شد، "
            "csrf_field() در فرم و csrf_check() در ابتدای پردازش الزامی است. */"
        )
        dash = dash.replace("<?php", "<?php\n" + note, 1)
        dp.write_text(dash, encoding="utf-8")

# ---- diagnostic endpoints must not ship ----
for name in ("dbdiag.php", "check.php"):
    p = ROOT / name
    if p.exists():
        p.unlink()

print("fixups applied: min_php=%s build=%s" % (min_php, new_build or "unchanged"))

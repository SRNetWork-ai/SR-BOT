#!/usr/bin/env bash
# بررسی سریع سینتکس همه فایل‌های PHP پروژه با php -l
# اجرا روی سرور یا سیستم توسعه:  bash tools/lint.sh
set -u
cd "$(dirname "$0")/.." || exit 1

if ! command -v php >/dev/null 2>&1; then
  echo "php CLI پیدا نشد. روی سرور: apt install php-cli یا dnf install php-cli"
  exit 2
fi

fail=0
count=0
while IFS= read -r -d '' f; do
  count=$((count+1))
  out=$(php -l "$f" 2>&1)
  if [ $? -ne 0 ]; then
    fail=1
    echo "✖ $f"
    echo "$out"
  fi
done < <(find . -path ./storage -prune -o -name '*.php' -print0)

if [ "$fail" -eq 0 ]; then
  echo "✔ OK — $count فایل PHP بدون خطای سینتکس"
else
  echo "✖ در فایل‌های بالا خطای سینتکس وجود دارد"
  exit 1
fi

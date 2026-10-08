"""Build an unreleased installable plugin ZIP from this checkout."""
import hashlib
import json
import subprocess
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parent.parent
target = root / 'artifacts' / 'nova-bridge-suite-2.9.0-rc.1.zip'
target.parent.mkdir(exist_ok=True)
files = subprocess.check_output(['git', 'ls-files', '-co', '--exclude-standard'], cwd=root, text=True).splitlines()
excluded = ('tests/', 'tools/', 'evidence/', 'artifacts/', '.github/', '.gitignore')
with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for relative in sorted(set(files)):
        path = root / relative
        if not path.is_file() or relative.startswith(excluded):
            continue
        archive.write(path, 'nova-bridge-suite/' + relative)
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None
    assert 'nova-bridge-suite/modules/service-page-cpt/includes/class-service-shared.php' in archive.namelist()
    assert 'Version: 2.9.0-rc.1' in archive.read('nova-bridge-suite/nova-bridge-suite.php').decode('utf-8')
metadata = {'file': target.name, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(), 'bytes': target.stat().st_size, 'base_release': 'v2.8.11', 'released': False}
(target.parent / 'candidate-build.json').write_text(json.dumps(metadata, indent=2), encoding='utf-8')
print(json.dumps(metadata, indent=2))

"""Extract the delivery schema graph from the agreed OpenAPI; requires PyYAML.

Usage: python extract-contract.py /path/to/posting-service.v1.yaml
The input hash is deliberately pinned; updating the contract requires review.
"""
import hashlib
import json
import pathlib
import sys
import yaml

COMMIT = "145fbcfb7319333e6369796489c068196cb68665"
SHA256 = "ce48ded00914e595dde23d543e347bc748155a7726a108331cc051dc8246af28"
raw = pathlib.Path(sys.argv[1]).read_bytes()
assert hashlib.sha256(raw).hexdigest() == SHA256, "Review and pin the new API release first"
all_schemas = yaml.safe_load(raw)["components"]["schemas"]
selected = {}

def visit(name):
    if name in selected:
        return
    selected[name] = all_schemas[name]
    scan(selected[name])

def scan(value):
    if isinstance(value, dict):
        if "$ref" in value:
            assert value["$ref"].startswith("#/components/schemas/")
            visit(value["$ref"].split("/")[-1])
        for child in value.values():
            scan(child)
    elif isinstance(value, list):
        for child in value:
            scan(child)

for root in ["DeliverySnapshot", "DeliveryPage", "ConnectorEventInput", "ConnectorEventAccepted",
             "PublishingTemplate", "PublishingTemplateInput", "PublishingTemplateDefaults", "PublishingTemplateDefaultsInput",
             "PublishingPageSetting", "PublishingPageSettingInput"]:
    visit(root)
output = pathlib.Path(__file__).parents[1] / "contracts" / "delivery-v1.json"
output.parent.mkdir(exist_ok=True)
output.write_text(json.dumps({"source_commit": COMMIT, "openapi_sha256": SHA256, "schemas": selected},
                            ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"Extracted {len(selected)} schemas: {output.name}")

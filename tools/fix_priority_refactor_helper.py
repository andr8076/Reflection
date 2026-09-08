from pathlib import Path

path = Path(__file__).with_name("refactor_priority_layers.py")
text = path.read_text(encoding="utf-8")
replacements = {
    'r"\\$wakeLayerStorePath = .*?@unlink\\(\\$wakeLayerStorePath \\. \'\\\\.lock\'\\);\\n"': 'r"\\$wakeLayerStorePath = .*?(?=\\$candidateWakeStorePath = )"',
    'r"\\n\\$candidateWakeStorePath = .*?@unlink\\(\\$candidateWakeStorePath \\. \'\\\\.lock\'\\);\\n"': 'r"\\$candidateWakeStorePath = .*?(?=\\$updateLayerStorePath = )"',
}
for old, new in replacements.items():
    if old not in text:
        raise RuntimeError(f"Expected matcher not found: {old}")
    text = text.replace(old, new, 1)
path.write_text(text, encoding="utf-8")
print("refactor helper matchers corrected")

# API Mapping Context

This standalone NOVA Bridge Suite module discovers relevant WordPress publishing endpoints and the fields NOVA can write to them. Administrators can select usable endpoints, map each API field to a NOVA content key, choose template-specific mappings, and add optional authoring guidance.

The module is independent from the Blog and Service CPT modules. Those NOVA-owned post types keep their dedicated REST context and are deliberately excluded from the generic endpoint inventory.

## Compatibility contracts

The module keeps the existing `Nova_Bridge_Suite_Content_Context` class name, legacy class include path, `nova_bridge_suite_content_contexts` option, schema version, REST routes, filters, JavaScript global, and DOM identifiers. Turning the module off stops discovery and context injection but does not delete saved mappings; they return when the module is enabled again.

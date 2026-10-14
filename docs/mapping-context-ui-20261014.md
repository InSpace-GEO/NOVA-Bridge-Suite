# Mapper context and relevant repeat controls

The content-rule inspector asks **What type of content should go in this field?** using the existing semantic choices. Field context is optional, under a closed **Extra Field Context** disclosure. Custom purposes need a clear name, but no mandatory instructions. Template instructions sit below the main and custom-field editors, under **Extra Template Context (optional)**.

The inspector no longer exposes source-binding controls or a duplicate full current-content viewer. Source pointers, saved context and recipe structures remain intact.

WooCommerce category top and bottom rich text are independent content fields. Their headings and paragraphs can vary without creating more CMS fields. Core's internal `sections` and `faqs` HTML wrappers are therefore excluded from the category repeat composer. Existing saved regions remain editable; opening them does not migrate or rewrite the recipe.

For genuine defined custom lists and ACF repeaters, the site schema already defines an item. Setup names the list, replaces all example rows and uses one native row pattern. It does not ask for source pointers, range selection, repetition behavior or pattern length. Declared empty custom lists retain their schema-only prototype support and field limits.

For page-builder groups, range and pattern controls remain necessary to distinguish one card from a heading/body group and protect the surrounding layout. Empty policy controls specify whether to remove a contentless group or retain its example. Optional-wrapper context and eligible Elementor tab grouping are under closed **Extra region options**; ordinary category and custom-list regions have no new mobile grouping control. Existing projections remain editable.

Validation: 69 mounted mapper workflows and 62 canvas checks plus 25 PHP canvas authorization/isolation checks pass. The tests exercise optional-context preview/save/reload, unchanged source bindings, legacy recipes, category filtering, defined-list full replacement and native tab eligibility.

Staging deployment changes only `modules/api-mapping-context/assets/layout-recipes.js`. A private baseline preserves its previous version, NOVA settings, saved mappings, category 107, source page 3846 and 14 retained review posts. Exact asset hash and unchanged baseline comparisons are checked after deployment. No backend, main-branch or pull-request changes are part of this work.

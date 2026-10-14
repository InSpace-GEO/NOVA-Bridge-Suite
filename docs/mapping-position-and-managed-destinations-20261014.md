# Mapper position and customer destinations

Editing within one mapper step and source retains document and inspector scroll, inventory scroll, open disclosures, focus and text selection across UI rebuilds. Intentional navigation to another step or source resets that view context. The existing reference iframe remains mounted when its source is unchanged.

Selecting a native field from the inventory scrolls only the reference iframe's own window. It no longer calls native `scrollIntoView`, which could propagate through the iframe and move the outer WordPress admin page. The outer mapper keeps its working position while the selected content is brought into the reference viewport.

NOVA-owned (`system_managed`) destinations are hidden from the customer layouts cards, setup badges and coverage counts. Saved-template choices are limited to eligible client destinations. The plugin's REST catalog and native CPT mappings remain available for NOVA-owned processing; no saved mapping or site content is removed.

The staging deployment contains three JavaScript assets only: `layout-recipes.js`, `recipe-canvas.js` and `endpoint-mappings.js`. Source pages, categories, retained review content, NOVA settings and saved mapping history are compared against a fresh private baseline. Neither backend is part of this change.

Validation: 84 mapper UI workflows, 19 destination workflows, 102 canvas assertions and 25 canvas authorization/isolation checks passed. Mounted tests cover native and nested field controls, text caret selection, repeated example rows, busy responses, viewport height reduction and intentional navigation.

Live staging checks preserved outer scroll at 728.89 px and inventory scroll at 405.56 px across a content-purpose change. An ordinary pointer click between fields preserved outer scroll at 330 px and inventory scroll at 405.56 px; keyboard activation did likewise. The layouts overview showed Pages, Posts, WooCommerce categories and Products (coming soon), with no NOVA-owned CPT cards. No test mapping was saved.

The private before/after comparison verified all three installed asset hashes, 128 unchanged settings, 15 unchanged retained items, unchanged source page 3846 and category 107, and unchanged original mapping history/defaults.

const {test} = require('node:test');
const assert = require('node:assert/strict');
const drafts = require('../assets/mapping-drafts.js');
const layout = {signature:'layout-a',reference_type:'post',reference_id:42,title:'Service page',post_type:'page',builders:['elementor']};
const template = {fields:[{source_path:'page_heading'},{source_path:'summary'},{source_path:'steps[].step_heading'},{source_path:'steps[].step_body'}],groups:[{key:'steps',min:2,max:6,member_keys:['step_heading','step_body']}]};
const inventory = ['/title','/meta_all/matrix_0_heading','/meta_all/matrix_0_body','/meta_all/matrix_1_heading','/meta_all/matrix_1_body','/form'].map(path=>({path,writable:true}));
function fixture(){return {...drafts.initialDraft(layout),template:{id:'preview-service',revision:'preview-1'},catalog_mode:'preview',fields:{'/title':{mode:'mapped',source_path:'page_heading',instructions:'One clear heading'},'/form':{mode:'protected',source_path:'',instructions:'Preserve form settings'}},skipped_sources:[{source_path:'summary',reason:'No summary in this layout'}],repeat_slots:{steps:[{id:'slot_a',targets:{step_heading:'/meta_all/matrix_0_heading',step_body:'/meta_all/matrix_0_body'}},{id:'slot_b',targets:{step_heading:'/meta_all/matrix_1_heading',step_body:'/meta_all/matrix_1_body'}}]}};}
test('coverage includes repeat members and justified skips, not protected target counts',()=>{const result=drafts.coverage(fixture(),template,inventory);assert.equal(result.total,4);assert.equal(result.mapped,3);assert.equal(result.skipped,1);assert.deepEqual(result.missing,[]);assert.deepEqual(result.issues,[]);});
test('an unjustified skip remains missing; absence of a required repeat member is visible',()=>{const draft=fixture();draft.skipped_sources[0].reason=' ';delete draft.repeat_slots.steps[1].targets.step_body;const result=drafts.coverage(draft,template,inventory);assert.equal(result.skipped,0);assert.deepEqual(result.missing.map(x=>x.source_path),['summary']);assert(result.issues.some(x=>x.includes('slot 2 has no target')));assert(result.issues.some(x=>x.includes('reason for skipping')));});
test('duplicate fixed targets and overlap with protected fields are identified',()=>{const draft=fixture();draft.repeat_slots.steps[0].targets.step_body='/form';draft.repeat_slots.steps[1].targets.step_heading='/title';const result=drafts.coverage(draft,template,inventory);assert.deepEqual(result.duplicates.sort(),['/form','/title']);});
test('saved bindings survive catalog/reference drift and payload exports no trusted descriptor',()=>{const draft=fixture();draft.revision='f0907771-f090-7771-f090-7771f0907771';draft.fields['/missing']={mode:'mapped',source_path:'old_field',instructions:'Retain me',binding:'untrusted',descriptor:{command:'no'},target_descriptor:{path:'no'}};const before=structuredClone(draft),result=drafts.payload(draft);assert.equal(result.expected_revision,draft.revision);assert.equal(result.fields['/missing'].source_path,'old_field');assert(!('binding' in result.fields['/missing']));assert(!('descriptor' in result.fields['/missing']));assert(!('target_descriptor' in result.fields['/missing']));assert.deepEqual(draft,before);assert(drafts.coverage(draft,template,inventory).issues.some(x=>x.includes('no longer in this reference')));});
test('saving a new draft does not import legacy leave-empty policies automatically',()=>{const draft=drafts.initialDraft({...layout,profile:{fields:{'/form':{mapping:'leave_empty'}}}});assert.deepEqual(draft.fields,{});assert.equal(draft.catalog_mode,'destination');assert.deepEqual(draft.template,{id:'local-destination',revision:'1'});assert.equal(draft.revision,'');assert.equal(drafts.payload(draft).expected_revision,'');});
test('payload keeps stable slot identities, order, source instructions and reference routing',()=>{const draft=fixture();draft.routing={operation:'clone',locale:'nl-NL'};draft.guidance='Explain the service';const result=drafts.payload(draft);assert.deepEqual(result.repeat_slots.steps.map(x=>x.id),['slot_a','slot_b']);assert.equal(result.fields['/title'].instructions,'One clear heading');assert.equal(result.fields['/form'].mode,'protected');assert.equal(result.guidance,draft.guidance);assert.deepEqual(result.routing,draft.routing);assert.equal(result.reference_id,42);});
test('new page mappings choose stored creation while historical updates and clones retain their intent',()=>{const fresh=drafts.initialDraft(layout);assert.equal(fresh.routing.operation,'create');assert.equal(fresh.routing.publication,'draft');assert.equal(drafts.initialDraft({...layout,builders:['avada']}).routing.operation,'update');assert.equal(drafts.initialDraft({...layout,post_type:'post'}).routing.operation,'update');fresh.stored_layout={blueprint:{privateNativeStyling:true}};assert(!('stored_layout' in drafts.payload(fresh)));assert.equal(drafts.initialDraft({...layout,reference_type:'term'}).routing.operation,'update');for(const operation of ['update','clone']){const old={...fresh,routing:{operation,publication:'preserve'}};assert.deepEqual(drafts.initialDraft(layout,old).routing,old.routing);}});
test('template switches retain stale repeat groups and identify unmapped new sources',()=>{const draft=fixture(),next={fields:[{source_path:'body'}],groups:[]};const result=drafts.coverage(draft,next,inventory);assert(result.issues.some(x=>x.includes('repeat group is absent')));assert.deepEqual(result.missing.map(x=>x.source_path),['body']);assert.equal(draft.repeat_slots.steps.length,2);});
test('a conflicting save preserves the draft and exposes HTTP409',async()=>{const originalFetch=global.fetch,draft=fixture(),before=structuredClone(draft);global.fetch=async()=>({ok:false,status:409,json:async()=>({code:'mapping_conflict',message:'Another editor saved this draft.'})});try{await assert.rejects(drafts.request('https://example.test/mapping/draft','nonce',drafts.payload(draft)),e=>e.status===409&&e.code==='mapping_conflict');assert.deepEqual(draft,before);}finally{global.fetch=originalFetch;}});
test('unreadable or unconfirmed responses cannot be reported as saved',async()=>{const originalFetch=global.fetch;global.fetch=async()=>({ok:true,status:200,json:async()=>{throw new Error('not json');}});try{await assert.rejects(drafts.request('/draft','nonce',{}),/unreadable response/);}finally{global.fetch=originalFetch;}assert.throws(()=>drafts.saveResult({draft:null}),/did not confirm/);assert.throws(()=>drafts.saveResult({draft:{revision:''}}),/did not confirm/);const saved={revision:'opaque-saved-version',guidance_mode:'inherit',fields:{},repeat_slots:{},skipped_sources:[],routing:{operation:'update',locale:''}};assert.deepEqual(drafts.saveResult({draft:saved}),saved);assert.notEqual(drafts.saveResult({draft:saved}),saved);});
test('empty PHP object arrays normalize to maps so first added fields survive serialization',()=>{const draft=drafts.initialDraft(layout,{revision:'opaque-id',fields:[],repeat_slots:[],skipped_sources:[]});draft.fields['/title']={mode:'mapped',source_path:'page_heading'};draft.repeat_slots.steps=[{id:'stable-slot',targets:{step_body:'/body'}}];const result=JSON.parse(JSON.stringify(drafts.payload(draft)));assert.equal(result.fields['/title'].source_path,'page_heading');assert.equal(result.repeat_slots.steps[0].id,'stable-slot');});
test('reference reconciliation is explicit and preserves the old draft until saved',()=>{const draft=fixture(),result=drafts.payload(draft,{signature:'layout-b',confirm_reference_change:true,discard_stale_targets:['/missing']});assert.equal(result.signature,'layout-b');assert.equal(result.confirm_reference_change,true);assert.deepEqual(result.discard_stale_targets,['/missing']);assert.equal(draft.signature,'layout-a');});
test('offline editing uses only the cached exact revision without claiming a live catalog',()=>{const draft=fixture();draft.catalog_snapshot={...template,id:'preview-service',revision:'preview-1'};const unavailable={origin:'unavailable',templates:[]};assert.equal(drafts.resolveTemplate(draft,unavailable),draft.catalog_snapshot);assert.equal(unavailable.origin,'unavailable');draft.template.revision='preview-2';assert.equal(drafts.resolveTemplate(draft,unavailable),undefined);});
test('live exact template supersedes cached editing aid; latest revision is not a substitute',()=>{const draft=fixture();draft.catalog_snapshot={...template,...draft.template};const other={...template,id:'preview-service',revision:'preview-2'};assert.equal(drafts.resolveTemplate(draft,{templates:[other]}),draft.catalog_snapshot);const exact={...template,...draft.template,label:'Live exact'};assert.equal(drafts.resolveTemplate(draft,{templates:[other,exact]}),exact);});
test('saved empty repeat slots retain new member bindings after PHP-array normalization',()=>{const restored=drafts.initialDraft(layout,{...fixture(),repeat_slots:{steps:[{id:'keep-me',targets:[]}]}});restored.repeat_slots.steps[0].targets.step_body='/meta_all/matrix_0_body';const serialized=JSON.parse(JSON.stringify(drafts.payload(restored)));assert.equal(serialized.repeat_slots.steps[0].id,'keep-me');assert.equal(serialized.repeat_slots.steps[0].targets.step_body,'/meta_all/matrix_0_body');});

test('new structural slots are unique lowercase UUIDs and are not derived from positions',()=>{const ids=Array.from({length:30},()=>drafts.newSlotId());assert.equal(new Set(ids).size,ids.length);for(const id of ids)assert.match(id,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);});
test('loading or exporting historical slots never invents UUIDs or ordinals',()=>{const old=fixture();const restored=drafts.initialDraft(layout,old);assert.deepEqual(restored.repeat_slots,old.repeat_slots);assert.deepEqual(drafts.payload(restored).repeat_slots,old.repeat_slots);assert(!('ordinal' in restored.repeat_slots.steps[0]));});
test('guidance inheritance, replacement and clear are separate saved intents',()=>{const fresh=drafts.initialDraft(layout);assert.equal(drafts.payload(fresh).guidance_mode,'inherit');const legacy=drafts.initialDraft(layout,{...fresh,guidance_mode:undefined,guidance:'Existing note'});assert.equal(legacy.guidance_mode,'set');legacy.guidance_mode='clear';assert.equal(drafts.payload(legacy).guidance_mode,'clear');assert.equal(legacy.guidance,'Existing note');});
test('guidance limits count UTF-8 bytes rather than characters',()=>{assert.equal(drafts.utf8Bytes('é'.repeat(4000)),8000);assert.equal(drafts.utf8Bytes('é'.repeat(4001)),8002);assert.equal(drafts.utf8Bytes('😀'.repeat(2000)),8000);});

test('destination descriptions derive native labels, formats and edge-case instructions',()=>{
 assert.deepEqual(drafts.describeField({path:'/hero/link',label:'Hero button destination',type:'string',format:'url',native_description:'Keep the URL relevant'}),{label:'Hero button destination',purpose:'Keep the URL relevant',value_type:'url',constraints:{}});
 assert.equal(drafts.describeField({path:'/bridge/widget',element:'text-editor',format:'html'}).value_type,'rich_text');
 assert.equal(drafts.describeField({path:'/content',type:'string'}).value_type,'rich_text');
 const unicode=drafts.describeField({path:'/title',label:'é'.repeat(101),native_description:'😀'.repeat(501)});assert.equal(drafts.utf8Bytes(unicode.label),200);assert.equal(drafts.utf8Bytes(unicode.purpose),2000);assert(!unicode.purpose.includes('�'));
 const previous={mode:'mapped',source_path:'title',required:true,instructions:'Exactly one label'},before=structuredClone(previous),converted=drafts.adaptField({path:'/title',label:'Hero title'},previous);
 assert.equal(converted.mode,'adapt');assert.equal(converted.source_path,'');assert.equal(converted.instructions,previous.instructions);assert.equal(converted.required,true);assert.equal(converted.description.label,'Hero title');assert.deepEqual(previous,before);
});
test('destination payload keeps rules, fixed capacity and optional values without a NOVA catalog',()=>{
 const draft=drafts.initialDraft(layout);draft.fields['/title']=drafts.adaptField({path:'/title',label:'First step'});draft.fields['/title'].description.constraints={max_length:40};
 const group={id:drafts.newSlotId(),label:'Existing steps',slots:[]};draft.destination_groups.push(group);const slot=drafts.addDestinationSlot(draft,group);drafts.assignDestinationField(draft,slot,'/title');
 const result=drafts.payload(draft);assert.equal(result.catalog_mode,'destination');assert.equal(result.fields['/title'].source_path,'');assert.equal(result.fields['/title'].required,false);assert.equal(result.fields['/title'].description.constraints.max_length,40);assert.deepEqual(result.destination_groups,draft.destination_groups);assert.equal(result.destination_groups[0].slots[0].ordinal,0);assert.notEqual(result.destination_groups[0].id,slot.id);assert.deepEqual(draft.repeat_slots,{});
});
test('fixed groups reject protected, compound and duplicate destination bindings',()=>{
 const draft=drafts.initialDraft(layout);draft.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});draft.fields['/form']={mode:'protected'};draft.fields['/link']=drafts.adaptField({path:'/link',type:'link'});
 const group={id:drafts.newSlotId(),label:'Rows',slots:[]};draft.destination_groups.push(group);const first=drafts.addDestinationSlot(draft,group),second=drafts.addDestinationSlot(draft,group);
 drafts.assignDestinationField(draft,first,'/title');assert.throws(()=>drafts.assignDestinationField(draft,second,'/title'),/only one existing slot/);assert.throws(()=>drafts.assignDestinationField(draft,second,'/form'),/adapted destination/);assert.throws(()=>drafts.assignDestinationField(draft,second,'/link'),/scalar/);assert.deepEqual(second.fields,[]);
 const before=structuredClone(draft);assert.deepEqual(drafts.initialDraft(layout,draft).destination_groups,before.destination_groups);assert.deepEqual(drafts.payload(draft).destination_groups,before.destination_groups);
});
test('destination coverage measures native destinations and leaves incomplete fields advisory',()=>{
 const draft=drafts.initialDraft(layout);draft.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});draft.fields['/form']={mode:'protected'};
 const result=drafts.coverage(draft,template,inventory);assert.equal(result.total,inventory.length);assert.equal(result.mapped,1);assert.equal(result.handled,2);assert.equal(result.skipped,0);assert.equal(result.missing.length,inventory.length-2);assert.deepEqual(result.issues,[]);assert(!result.missing.some(field=>field.source_path));
});
test('destination coverage exposes historical source bindings and preserves them until explicit conversion',()=>{
 const legacy=fixture(),before=structuredClone(legacy),destination={...legacy,catalog_mode:'destination',destination_groups:[]};const result=drafts.coverage(destination,template,inventory);
 assert(result.issues.some(issue=>issue.includes('Historical source binding')));assert.deepEqual(destination.repeat_slots,before.repeat_slots);assert.deepEqual(destination.fields,before.fields);assert.deepEqual(drafts.payload(destination).repeat_slots,before.repeat_slots);
});
test('legacy migration selects the local destination identity while retaining rules and bindings for review',()=>{
 const legacy=fixture(),before=structuredClone(legacy),catalog={...template,...legacy.template,authoring_notes:'Retain one relevant label'};drafts.prepareDestination(legacy,catalog);
 assert.equal(legacy.catalog_mode,'destination');assert.deepEqual(legacy.template,{id:'local-destination',revision:'1'});assert.deepEqual(legacy.fields,before.fields);assert.deepEqual(legacy.repeat_slots,before.repeat_slots);assert.deepEqual(legacy.skipped_sources,before.skipped_sources);assert.equal(legacy.guidance_mode,'set');assert.equal(legacy.guidance,catalog.authoring_notes);assert.deepEqual(legacy.catalog_snapshot,catalog);assert.deepEqual(drafts.payload(legacy).template,{id:'local-destination',revision:'1'});
});
test('duplicate fixed slot fields, invalid IDs and missing native destinations are visible',()=>{
 const draft=drafts.initialDraft(layout);draft.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});const id=drafts.newSlotId();draft.destination_groups=[{id,label:'Rows',slots:[{id,ordinal:1,fields:['/title','/title','/missing']}]}];
 const result=drafts.coverage(draft,null,inventory);assert.deepEqual(result.duplicates,['/title']);assert(result.issues.some(issue=>issue.includes('unique structural UUID')));assert(result.issues.some(issue=>issue.includes('only adapted destinations')));assert(result.issues.some(issue=>issue.includes('no longer in this reference')));
});

// Reuse the minimal DOM approach used by the posting status controller tests.
class Element {
 constructor(tag,document){this.tag=tag;this.ownerDocument=document;this.children=[];this.attributes={};this.events={};this.dataset={};this.value='';this.isConnected=true;this.ownText='';this.classList={toggle(){}};}
 set textContent(value){this.ownText=String(value);this.children=[];}
 get textContent(){return this.ownText+this.children.map(child=>child.textContent).join(' ');}
 appendChild(child){child.parentNode=this;this.children.push(child);return child;}
 append(...children){children.forEach(child=>this.appendChild(child));}
 prepend(child){child.parentNode=this;this.children.unshift(child);}
 replaceChildren(...children){this.children=[];this.ownText='';this.append(...children);}
 setAttribute(name,value){this.attributes[name]=value;}
 addEventListener(name,callback){this.events[name]=callback;}
 remove(){if(this.parentNode)this.parentNode.children=this.parentNode.children.filter(child=>child!==this);this.isConnected=false;}
 scrollIntoView(){}
 click(){return this.events.click?.({preventDefault(){}});}
 all(){return [this,...this.children.flatMap(child=>child.all())];}
}
async function editorFixture(saved=null,options={},externalChrome=false,fixtureOptions={}){
 const document={createElement:tag=>new Element(tag,document)},host=new Element('div',document),calls=[],responses=[];
 const chrome=externalChrome?new Element('div',document):null;if(chrome){const sibling=new Element('p',document);sibling.textContent='Independent sibling';chrome.appendChild(sibling);}
 global.fetch=async(url,options)=>{calls.push({url,options});if(responses.length){const response=responses.shift();return {ok:true,status:200,json:async()=>response};}return {ok:true,status:200,json:async()=>({draft:saved,reference:{signature:layout.signature},warnings:[]})};};
 const controller=drafts.mount(host,{...layout,fields:fixtureOptions.fields||[{path:'/title',label:'Hero title',type:'text',writable:true},{path:'/form',label:'Form',type:'object',writable:false}]},{mappingUrl:'/mapping',nonce:'test-nonce',postingIntegration:true,...options},fixtureOptions.callbacks||{},{chromeHost:chrome});await controller.ready;
 return {host,chrome,calls,responses,controller,button:label=>host.all().find(item=>item.tag==='button'&&item.ownText===label),control:label=>host.all().find(item=>item.tag==='label'&&item.ownText===label)?.parentNode.children.find(item=>['input','select','textarea'].includes(item.tag))};
}
test('creation review offers explicit draft or publish while legacy clone appears only when already selected',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  view=await editorFixture();view.button('Use this reference').click();view.button('Review setup').click();
  let operation=view.control('Intended operation');assert.equal(operation.value,'create');assert.deepEqual(operation.children.map(item=>item.value),['create','update']);assert.deepEqual(view.control('Publication policy').children.map(item=>item.value),['publish','draft']);
  operation.value='update';operation.events.change();assert.equal(view.controller.getDraft().routing.operation,'update');assert(view.control('Publication policy').children.some(item=>item.value==='preserve'));
  view.controller.destroy();const old={...drafts.initialDraft(layout),revision:'old',routing:{operation:'clone',locale:'',publication:'preserve'}};view=await editorFixture(old,{view:'workspace'});assert(view.control('Intended operation').children.some(item=>item.value==='clone'));assert.equal(view.controller.getDraft().routing.operation,'clone');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('V3 synchronization is explicit and native approval uses the reviewed saved revision',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=drafts.initialDraft(layout);saved.revision='v3-reviewed';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Hero heading'});saved.fields['/title'].description.purpose='Introduction above products';
  view=await editorFixture(saved,{view:'workspace'});
  assert(view.calls.every(call=>call.options.method==='GET'),'Opening an existing V3 setup does not synchronize it');
  const state={status:'synced_draft',local_revision:saved.revision,template:{id:'11111111-1111-4111-8111-111111111111',revision:1}};
  view.responses.push({state});view.button('Synchronize publishing template').click();await new Promise(resolve=>setImmediate(resolve));
  const sync=view.calls.at(-1);assert.equal(sync.url,'/mapping/sync');assert.equal(sync.options.method,'POST');assert.equal(JSON.parse(sync.options.body).expected_revision,'v3-reviewed');
  assert(view.host.textContent.includes('field purposes and instructions'));assert.equal(view.button('Validate and approve native profile').disabled,false);
  view.responses.push({state:{...state,status:'active'}});view.button('Validate and approve native profile').click();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(view.calls.at(-1).url,'/mapping/activate');assert(view.host.textContent.includes('Native profile approved for this exact template revision'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('new destination editor saves locally and exposes explicit V3 synchronization',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  view=await editorFixture();assert.equal(view.calls.length,1);assert(view.calls[0].url.startsWith('/mapping/draft?'));assert(!view.control('Delivery field catalog'));assert(!view.control('NOVA source'));assert(view.host.textContent.includes('V3 destination mapping')&&view.button('Synchronize publishing template').disabled);
  const mode=view.control('Field behavior');mode.value='adapt';mode.events.change();assert.equal(view.controller.getDraft().fields['/title'].description.label,'Hero title');assert(!view.control('NOVA source'));assert(!view.control('Minimum list items'));
  const maximum=view.control('Maximum characters');maximum.value='40';maximum.events.input();assert.equal(view.controller.getDraft().fields['/title'].description.constraints.max_length,40);
  view.button('Describe a fixed existing group').click();const chosen=view.control('Add a field to this existing slot');chosen.value='/title';chosen.events.change();const first=view.controller.getDraft().destination_groups[0].slots[0];assert.deepEqual(first.fields,['/title']);
  const type=view.control('What this field can hold');type.value='list';type.events.change();assert.equal(view.controller.getDraft().fields['/title'].description.value_type,'text');assert(view.host.textContent.includes('Unassign this field'));
  view.button('Unassign field').click();type.value='list';type.events.change();assert(!view.control('Maximum characters'));assert(view.control('Minimum list items'));assert.deepEqual(view.controller.getDraft().fields['/title'].description.constraints,{});
  view.button('Use this reference').click();view.button('Review setup').click();const current=view.controller.getDraft();current.revision='saved-destination';current.destination_groups=[];view.responses.push({draft:current,warnings:[]});await view.button('Save setup').click();const request=view.calls[1];assert.equal(request.options.method,'POST');const sent=JSON.parse(request.options.body);assert.equal(sent.catalog_mode,'destination');assert.equal(sent.fields['/title'].mode,'adapt');assert.equal(sent.fields['/title'].source_path,'');assert.equal(view.calls.length,2);assert(view.host.textContent.includes('Setup saved on this site'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('backend description export rejects a changed saved revision before downloading',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=drafts.initialDraft(layout);saved.revision='reviewed-revision';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});view=await editorFixture(saved);
  view.responses.push({draft:{...saved,revision:'someone-elses-revision'},backend_description:{status:'prepared_local',description:{fields:[]}}});await view.button('Export handover').click();assert.equal(view.calls.length,2);assert.equal(view.calls[1].options.method,'GET');assert(view.host.textContent.includes('The saved draft changed'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('legacy editor switches to the destination sentinel without silently converting bindings',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=fixture();saved.revision='legacy-revision';saved.catalog_snapshot={...template,...saved.template,authoring_notes:'One relevant label'};view=await editorFixture(saved);view.button('Prepare destination descriptions instead').click();const migrated=view.controller.getDraft();assert.deepEqual(migrated.template,{id:'local-destination',revision:'1'});assert.equal(migrated.catalog_mode,'destination');assert.deepEqual(migrated.fields,saved.fields);assert.deepEqual(migrated.repeat_slots,saved.repeat_slots);assert.equal(migrated.guidance,'One relevant label');assert(!view.control('Delivery field catalog'));assert(!view.control('NOVA source'));assert(view.button('Remove retained legacy repeat bindings after review'));assert(view.host.textContent.includes('V3 destination mapping')&&view.button('Synchronize publishing template').disabled);
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('backend description export downloads the saved proposal and refuses unsaved edits',async()=>{
 const originalFetch=global.fetch,originalObjectURL=URL.createObjectURL;let view,exported;
 try{
  URL.createObjectURL=blob=>{exported=blob;return 'blob:local-test';};const saved=drafts.initialDraft(layout);saved.revision='reviewed-revision';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});view=await editorFixture(saved);
  const proposal={status:'prepared_local',local_revision:saved.revision,sha256:'a'.repeat(64),description:{format:'nova-template-description/v1',fields:[{id:'field_title',label:'Title',value_type:'text'}]}};view.responses.push({draft:saved,backend_description:proposal});await view.button('Export handover').click();assert.deepEqual(JSON.parse(await exported.text()),proposal);assert(view.host.textContent.includes('local preparation'));
  const label=view.control('Destination label');label.value='Changed title';label.events.input();await view.button('Export handover').click();assert.equal(view.calls.length,2);assert(view.host.textContent.includes('Save and review this destination draft'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;URL.createObjectURL=originalObjectURL;}
});

test('guided existing-layout setup preserves edits across review, saves before completion and opens a workspace',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  view=await editorFixture(null,{view:'setup'});const stage=name=>view.host.all().find(item=>item.className?.split(' ').includes(name));
  assert.equal(stage('nmd-choose').hidden,false);assert.equal(stage('nmd-stage-fields').hidden,true);assert.equal(view.button('Save setup').hidden,true);assert.equal(view.button('4. Save & hand over').disabled,true);
  view.button('4. Save & hand over').click();assert.equal(stage('nmd-complete').hidden,true,'Unsaved preparation cannot skip to completion');
  view.button('Use this reference').click();assert.equal(stage('nmd-stage-fields').hidden,false);
  const mode=view.control('Field behavior');mode.value='adapt';mode.events.change();const label=view.control('Destination label');label.value='Prepared hero heading';label.events.input();
  const before=view.controller.getDraft();view.button('Review setup').click();assert.equal(stage('nmd-stage-review').hidden,false);assert.equal(stage('nmd-stage-fields').hidden,true);assert(view.host.textContent.includes('Saving stores the example page structure and styling'));
  view.button('Back to fields').click();assert.deepEqual(view.controller.getDraft(),before);assert.equal(view.control('Destination label').value,'Prepared hero heading');assert.equal(view.calls.length,1,'Moving between steps performs no save or synchronization');
  view.button('Review setup').click();const saved=view.controller.getDraft();saved.revision='saved-setup';view.responses.push({draft:saved,warnings:[]});await view.button('Save setup').click();
  assert.equal(stage('nmd-complete').hidden,false);assert.equal(view.controller.getDraft().revision,'saved-setup');assert.equal(view.calls.length,2);assert.equal(view.calls[1].url,'/mapping/draft');
  view.button('Open saved setup').click();assert.equal(stage('nmd-stage-fields').hidden,false);assert.equal(stage('nmd-stage-review').hidden,false);assert.equal(view.button('Save changes').hidden,false);assert.equal(view.button('Use this reference').hidden,true);assert.equal(view.calls.length,3);assert(view.calls[2].url.startsWith('/mapping/sync-state?'));assert.equal(view.calls[2].options.method,'GET','Opening a workspace only reads local synchronization state');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

test('explicit saved workspace and setup links keep distinct journeys; reference clicks open fields without changing draft',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=drafts.initialDraft(layout);saved.revision='local-revision';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Keep this description'});
  view=await editorFixture(saved,{view:'workspace'});assert(view.button('Save changes'));assert(!view.button('1. Choose reference'));assert.equal(view.host.all().find(item=>item.className?.split(' ').includes('nmd-delivery-advanced')).hidden,false);
  view.controller.destroy();view=await editorFixture(saved,{view:'setup'});assert(view.button('1. Choose reference'));assert.equal(view.host.all().find(item=>item.className?.split(' ').includes('nmd-choose')).hidden,false);
  const before=view.controller.getDraft();view.controller.selectField('/title');assert.equal(view.host.all().find(item=>item.className?.split(' ').includes('nmd-stage-fields')).hidden,true,'Reference selection is confirmed before preview fields can advance the guide');view.button('Use this reference').click();view.controller.selectField('/title');assert.equal(view.host.all().find(item=>item.className?.split(' ').includes('nmd-stage-fields')).hidden,false);assert.deepEqual(view.controller.getDraft(),before);
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

test('setup status separates local preparation, remote synchronization and exact writer approval',()=>{
 const prepared=drafts.initialDraft(layout);prepared.revision='one';assert.equal(drafts.setupState(prepared,{status:'active',local_revision:'one'},false).label,'Writer approved');
 const connected={...prepared,catalog_mode:'nova'};assert.equal(drafts.setupState(connected,{status:'synced_draft',local_revision:'one'},false).label,'Template synchronized');assert.equal(drafts.setupState(connected,{status:'active',local_revision:'one'},false).label,'Writer approved');
 assert.equal(drafts.setupState(connected,{status:'active',local_revision:'other'},false).label,'Saved locally');assert.equal(drafts.setupState(prepared,{status:'active',local_revision:'one',mapping_version:1},false).label,'Synchronize mapping version');assert.equal(drafts.setupState(connected,{status:'active',local_revision:'one'},true).label,'Unsaved changes');
 const before=structuredClone(prepared);prepared.fields={'/title':{mode:'adapt'},'/form':{mode:'protected'},'/empty':{mode:'leave_empty'}};prepared.routing.operation='clone';
 assert.deepEqual(drafts.impactSummary(prepared,[{path:'/title'},{path:'/form'},{path:'/empty'},{path:'/other'}]),{described:1,delivered:0,preserved:1,omitted:1,unconfigured:1,operation:'clone',preparation:true});assert.equal(prepared.revision,before.revision,'Impact review does not change stored configuration');
});

test('full-width external guide chrome has one stepper and preserves the inspector draft across navigation and save',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  view=await editorFixture(null,{view:'setup'},true);const chromeButton=label=>view.chrome.all().find(item=>item.tag==='button'&&item.ownText===label),steppers=()=>view.chrome.all().filter(item=>item.className==='nmd-steps');
  assert.equal(steppers().length,1);assert.equal(view.host.all().filter(item=>item.className==='nmd-steps').length,0,'The inspector does not duplicate the relocated navigation');assert(view.chrome.textContent.includes('Set up this existing layout'));
  assert.equal(chromeButton('2. Describe fields').disabled,true);view.button('Use this reference').click();const mode=view.control('Field behavior');mode.value='adapt';mode.events.change();const label=view.control('Destination label');label.value='External chrome edit';label.events.input();const before=view.controller.getDraft();assert(view.chrome.textContent.includes('Unsaved changes'));
  view.button('Review setup').click();chromeButton('2. Describe fields').click();assert.deepEqual(view.controller.getDraft(),before);assert.equal(view.control('Destination label').value,'External chrome edit');assert.equal(steppers().length,1);assert.equal(view.calls.length,1);
  view.button('Review setup').click();const saved=view.controller.getDraft();saved.revision='external-chrome-saved';view.responses.push({draft:saved,warnings:[]});await view.button('Save setup').click();assert.equal(steppers().length,1);assert(view.chrome.textContent.includes('Saved locally'));assert.equal(view.controller.getDraft().fields['/title'].description.label,'External chrome edit');
  view.button('Open saved setup').click();assert.equal(steppers().length,0,'Saved workspace keeps the header and omits onboarding navigation');assert(view.chrome.textContent.includes('Saved locally'));view.controller.destroy();assert.equal(view.chrome.textContent,'Independent sibling','Destroy removes only this mount’s chrome');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

test('guided content inventory prioritizes editable layout content and leaves technical discovery intact',()=>{
 const fields=[{path:'/title',type:'text'},{path:'/status',type:'text'},{path:'/content',type:'html'},{path:'/elements/heading/title',builder:'elementor',type:'text'},{path:'/elements/heading/color',builder:'elementor',type:'text'},{path:'/form',type:'object',writable:false},{path:'/repeater',type:'repeater'},{path:'/acf/body',source:'acf',type:'wysiwyg'},{path:'/author/name',type:'text'},{path:'/missing',type:'text',writable:false},{path:'/elements/text/editor',builder:'elementor',type:'wysiwyg'}];
 const before=structuredClone(fields);assert.deepEqual(drafts.guidedFields(fields).map(field=>field.path),['/elements/heading/title','/acf/body','/elements/text/editor','/content','/title']);assert.deepEqual(fields,before,'The guide only filters its view; discovery and writer capabilities are unchanged');
});

test('focused guide navigates one field and synchronizes preview selection without changing skipped or retained bindings',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const fields=[{path:'/title',label:'Post title',type:'text'},{path:'/hero',label:'Heading',builder:'elementor',type:'text',current_value:'<h1>First heading</h1>'},{path:'/body',label:'Article',builder:'elementor',type:'wysiwyg',current_value:'Current article'},{path:'/legacy',label:'Earlier heading',builder:'elementor',type:'text'},{path:'/form',label:'Form',type:'object',writable:false}],saved=drafts.initialDraft(layout),selections=[],journeys=[];
  saved.revision='retained';saved.fields['/body']={mode:'protected',instructions:'Keep original',required:false,source_path:''};saved.fields['/legacy']={mode:'mapped',source_path:'content.title',required:true,instructions:'Prior rule'};const before=structuredClone(saved);
  view=await editorFixture(saved,{view:'setup'},false,{fields,callbacks:{onSelectField:path=>selections.push(path),onJourneyChange:journey=>journeys.push(journey)}});
  const fieldCards=()=>view.host.all().filter(item=>item.className==='nmd-field');view.button('Use this reference').click();assert.equal(fieldCards().length,1);assert.equal(fieldCards()[0].dataset.path,'/hero');assert(view.control('Choose a content field').children.some(option=>option.textContent.includes('First heading')));
  view.button('Skip for now').click();assert.equal(fieldCards()[0].dataset.path,'/body');assert.equal(selections.at(-1),'/body');assert.deepEqual(view.controller.getDraft(),before);assert.equal(view.controller.isDirty(),false,'Skipping never marks preservation or changes a field');
  view.button('Next field').click();assert.equal(fieldCards()[0].dataset.path,'/legacy');assert.equal(selections.at(-1),'/legacy');assert(!view.control('Field behavior'),'Earlier source bindings cannot be silently converted in the guide');assert.deepEqual(view.controller.getDraft(),before);
  view.controller.selectField('/hero');assert.equal(fieldCards()[0].dataset.path,'/hero','A preview selection rebuilds the one-field card and dropdown');assert.equal(view.control('Choose a content field').value,'/hero');const mode=view.control('Field behavior');mode.value='adapt';mode.events.change();const purpose=view.control('What content belongs here?');purpose.value='Introduce the article in one clear heading';purpose.events.input();
  assert.equal(view.controller.getDraft().fields['/hero'].description.purpose,purpose.value);assert.deepEqual(view.controller.getDraft().fields['/body'],before.fields['/body']);assert.deepEqual(view.controller.getDraft().fields['/legacy'],before.fields['/legacy']);assert.equal(view.host.all().find(item=>item.className==='nmd-field-advanced').open,undefined,'Optional constraints stay collapsed');
  view.button('Review setup').click();assert.equal(journeys.at(-1).step,3);assert.equal(view.calls.length,1,'Review does not save, activate or synchronize');view.button('Back to fields').click();assert.equal(view.control('What content belongs here?').value,purpose.value);
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

test('a fresh workspace URL still requires reference confirmation and reviewed save before completion',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const journeys=[];view=await editorFixture(null,{view:'workspace'},false,{callbacks:{onJourneyChange:journey=>journeys.push(journey)}});assert.equal(journeys.at(-1).view,'setup');assert.equal(journeys.at(-1).step,1);view.controller.selectField('/title');assert.equal(journeys.at(-1).step,1,'A stale workspace link cannot bypass choosing a reference');
  view.controller.goStep(3);view.button('3. Review setup').click();await view.button('Save setup').click();assert.equal(journeys.at(-1).step,1);assert.equal(view.calls.length,1,'Hidden or disabled actions also refuse forward bypasses');
  view.button('Use this reference').click();view.controller.goStep(4);assert.equal(journeys.at(-1).step,2);view.button('Review setup').click();const saved=view.controller.getDraft();saved.revision='reviewed';view.responses.push({draft:saved,warnings:[]});await view.button('Save setup').click();assert.equal(journeys.at(-1).step,4);assert.equal(view.calls.length,2);view.button('Open saved setup').click();assert.equal(journeys.at(-1).view,'workspace');assert.equal(view.calls.length,3);assert(view.calls[2].url.startsWith('/mapping/sync-state?'));assert.equal(view.calls[2].options.method,'GET');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

test('new guided descriptions replace exact bridge boilerplate with a blank human prompt while retaining meaningful and saved purposes',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const boilerplate='Actual field returned by the NOVA bridge for the selected source document.',meaningful='Explain the service benefit for a first-time visitor',fields=[{path:'/hero',label:'Heading',builder:'elementor',type:'text',native_description:boilerplate},{path:'/body',label:'Article',builder:'elementor',type:'wysiwyg',native_description:meaningful},{path:'/title',label:'Saved heading',type:'text',native_description:boilerplate}],saved=drafts.initialDraft(layout);saved.revision='retained-purpose';saved.fields['/title']=drafts.adaptField(fields[2]);
  view=await editorFixture(saved,{view:'setup'},false,{fields});view.button('Use this reference').click();let mode=view.control('Field behavior');mode.value='adapt';mode.events.change();assert.equal(view.controller.getDraft().fields['/hero'].description.purpose,'');assert.equal(view.control('What content belongs here?').value,'');assert(view.control('What content belongs here?').parentNode.textContent.includes('a short heading introducing this section'),'The empty new purpose retains a human example');
  view.controller.selectField('/body');mode=view.control('Field behavior');mode.value='adapt';mode.events.change();assert.equal(view.control('What content belongs here?').value,meaningful);assert.equal(view.controller.getDraft().fields['/body'].description.purpose,meaningful);
  view.controller.selectField('/title');mode=view.control('Field behavior');mode.value='adapt';mode.events.change();assert.equal(view.control('What content belongs here?').value,boilerplate,'Even an earlier saved boilerplate purpose is preserved until a human edits it');assert.equal(drafts.describeField(fields[0]).purpose,boilerplate,'Library and workspace defaults retain their prior contract');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});


test('mapping version follows fitting intent rather than native nesting',()=>{
 const direct={catalog_mode:'nova',guidance_mode:'inherit',fields:{'/acf/hero/rows/0/title':{mode:'mapped',source_path:'h1',instructions:''},'/footer':{mode:'protected',instructions:'Keep this note'}}};
 assert.equal(drafts.mappingVersion(direct),1);
 const fieldNotes=structuredClone(direct);fieldNotes.fields['/acf/hero/rows/0/title'].instructions='Select a short heading';assert.equal(drafts.mappingVersion(fieldNotes),3);
 const inherited={...direct,catalog_snapshot:{authoring_notes:'Use the introductory section'}};assert.equal(drafts.mappingVersion(inherited),3);assert.equal(drafts.mappingVersion({...inherited,guidance_mode:'clear'}),1);
 assert.equal(drafts.mappingVersion({...direct,guidance_mode:'set',guidance:'Fit these destinations'}),3);
 assert.equal(drafts.mappingVersion({...direct,destination_groups:[{id:'fixed'}]}),3);
});

test('editor offers direct copies and explains automatic fitting when instructions are added',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=drafts.initialDraft(layout);saved.revision='direct-choice';view=await editorFixture(saved,{view:'workspace'});
  view.responses.push({origin:'nova',api_available:true,templates:[{id:'nova-delivery-fields-v1',revision:'1',label:'Delivery fields',family:'page',authoring_notes:'',fields:[{source_path:'h1',label:'Heading',type:'text'}],groups:[],protected_slots:[]}]});
  await view.button('Copy delivery fields directly').click();await new Promise(resolve=>setImmediate(resolve));
  assert.equal(view.controller.getDraft().catalog_mode,'nova');assert.equal(drafts.mappingVersion(view.controller.getDraft()),1);
  const mode=view.control('Field behavior');mode.value='mapped';mode.events.change();const source=view.control('NOVA source');source.value='h1';source.events.change();
  assert.equal(drafts.mappingVersion(view.controller.getDraft()),1);assert(view.host.textContent.includes('without LLM fitting'));
  const notes=view.control('Human instructions for this field');notes.value='Select the introductory heading';notes.events.input();
  assert.equal(drafts.mappingVersion(view.controller.getDraft()),3);assert(view.host.textContent.includes('V3 destination mapping'));assert.equal(view.controller.getDraft().fields['/title'].source_path,'h1');
  assert.equal(view.calls.filter(call=>call.options.method==='POST').length,0,'Changing intent never silently saves or synchronizes');
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});

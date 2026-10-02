const {test} = require('node:test');
const assert = require('node:assert/strict');
const drafts = require('../assets/mapping-drafts.js');
const layout = {signature:'layout-a',reference_type:'post',reference_id:42,title:'Service page'};
const template = {fields:[{source_path:'page_heading'},{source_path:'summary'},{source_path:'steps[].step_heading'},{source_path:'steps[].step_body'}],groups:[{key:'steps',min:2,max:6,member_keys:['step_heading','step_body']}]};
const inventory = ['/title','/meta_all/matrix_0_heading','/meta_all/matrix_0_body','/meta_all/matrix_1_heading','/meta_all/matrix_1_body','/form'].map(path=>({path,writable:true}));
function fixture(){return {...drafts.initialDraft(layout),template:{id:'preview-service',revision:'preview-1'},catalog_mode:'preview',fields:{'/title':{mode:'mapped',source_path:'page_heading',instructions:'One clear heading'},'/form':{mode:'protected',source_path:'',instructions:'Preserve form settings'}},skipped_sources:[{source_path:'summary',reason:'No summary in this layout'}],repeat_slots:{steps:[{id:'slot_a',targets:{step_heading:'/meta_all/matrix_0_heading',step_body:'/meta_all/matrix_0_body'}},{id:'slot_b',targets:{step_heading:'/meta_all/matrix_1_heading',step_body:'/meta_all/matrix_1_body'}}]}};}
test('coverage includes repeat members and justified skips, not protected target counts',()=>{const result=drafts.coverage(fixture(),template,inventory);assert.equal(result.total,4);assert.equal(result.mapped,3);assert.equal(result.skipped,1);assert.deepEqual(result.missing,[]);assert.deepEqual(result.issues,[]);});
test('an unjustified skip remains missing; absence of a required repeat member is visible',()=>{const draft=fixture();draft.skipped_sources[0].reason=' ';delete draft.repeat_slots.steps[1].targets.step_body;const result=drafts.coverage(draft,template,inventory);assert.equal(result.skipped,0);assert.deepEqual(result.missing.map(x=>x.source_path),['summary']);assert(result.issues.some(x=>x.includes('slot 2 has no target')));assert(result.issues.some(x=>x.includes('reason for skipping')));});
test('duplicate fixed targets and overlap with protected fields are identified',()=>{const draft=fixture();draft.repeat_slots.steps[0].targets.step_body='/form';draft.repeat_slots.steps[1].targets.step_heading='/title';const result=drafts.coverage(draft,template,inventory);assert.deepEqual(result.duplicates.sort(),['/form','/title']);});
test('saved bindings survive catalog/reference drift and payload exports no trusted descriptor',()=>{const draft=fixture();draft.revision='f0907771-f090-7771-f090-7771f0907771';draft.fields['/missing']={mode:'mapped',source_path:'old_field',instructions:'Retain me',binding:'untrusted',descriptor:{command:'no'},target_descriptor:{path:'no'}};const before=structuredClone(draft),result=drafts.payload(draft);assert.equal(result.expected_revision,draft.revision);assert.equal(result.fields['/missing'].source_path,'old_field');assert(!('binding' in result.fields['/missing']));assert(!('descriptor' in result.fields['/missing']));assert(!('target_descriptor' in result.fields['/missing']));assert.deepEqual(draft,before);assert(drafts.coverage(draft,template,inventory).issues.some(x=>x.includes('no longer in this reference')));});
test('saving a new draft does not import legacy leave-empty policies automatically',()=>{const draft=drafts.initialDraft({...layout,profile:{fields:{'/form':{mapping:'leave_empty'}}}});assert.deepEqual(draft.fields,{});assert.equal(draft.catalog_mode,'destination');assert.deepEqual(draft.template,{id:'local-destination',revision:'1'});assert.equal(draft.revision,'');assert.equal(drafts.payload(draft).expected_revision,'');});
test('payload keeps stable slot identities, order, source instructions and reference routing',()=>{const draft=fixture();draft.routing={operation:'clone',locale:'nl-NL'};draft.guidance='Explain the service';const result=drafts.payload(draft);assert.deepEqual(result.repeat_slots.steps.map(x=>x.id),['slot_a','slot_b']);assert.equal(result.fields['/title'].instructions,'One clear heading');assert.equal(result.fields['/form'].mode,'protected');assert.equal(result.guidance,draft.guidance);assert.deepEqual(result.routing,draft.routing);assert.equal(result.reference_id,42);});
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
async function editorFixture(saved=null){
 const document={createElement:tag=>new Element(tag,document)},host=new Element('div',document),calls=[],responses=[];
 global.fetch=async(url,options)=>{calls.push({url,options});if(responses.length){const response=responses.shift();return {ok:true,status:200,json:async()=>response};}return {ok:true,status:200,json:async()=>({draft:saved,reference:{signature:layout.signature},warnings:[]})};};
 const controller=drafts.mount(host,{...layout,fields:[{path:'/title',label:'Hero title',type:'text',writable:true},{path:'/form',label:'Form',type:'object',writable:false}]},{mappingUrl:'/mapping',nonce:'test-nonce',postingIntegration:true});await controller.ready;
 return {host,calls,responses,controller,button:label=>host.all().find(item=>item.tag==='button'&&item.ownText===label),control:label=>host.all().find(item=>item.tag==='label'&&item.ownText===label)?.parentNode.children.find(item=>['input','select','textarea'].includes(item.tag))};
}
test('new destination editor operates offline, creates rules and groups, saves and never attempts backend sync',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  view=await editorFixture();assert.equal(view.calls.length,1);assert(view.calls[0].url.startsWith('/mapping/draft?'));assert(!view.control('Delivery field catalog'));assert(!view.control('NOVA source'));assert(view.button('Backend synchronization unavailable').disabled);
  const mode=view.control('Field behavior');mode.value='adapt';mode.events.change();assert.equal(view.controller.getDraft().fields['/title'].description.label,'Hero title');assert(!view.control('NOVA source'));assert(!view.control('Minimum list items'));
  const maximum=view.control('Maximum characters');maximum.value='40';maximum.events.input();assert.equal(view.controller.getDraft().fields['/title'].description.constraints.max_length,40);
  view.button('Describe a fixed existing group').click();const chosen=view.control('Add a field to this existing slot');chosen.value='/title';chosen.events.change();const first=view.controller.getDraft().destination_groups[0].slots[0];assert.deepEqual(first.fields,['/title']);
  const type=view.control('What this field can hold');type.value='list';type.events.change();assert.equal(view.controller.getDraft().fields['/title'].description.value_type,'text');assert(view.host.textContent.includes('Unassign this field'));
  view.button('Unassign field').click();type.value='list';type.events.change();assert(!view.control('Maximum characters'));assert(view.control('Minimum list items'));assert.deepEqual(view.controller.getDraft().fields['/title'].description.constraints,{});
  const current=view.controller.getDraft();current.revision='saved-destination';current.destination_groups=[];view.responses.push({draft:current,warnings:[]});await view.button('Save local draft').click();const request=view.calls[1];assert.equal(request.options.method,'POST');const sent=JSON.parse(request.options.body);assert.equal(sent.catalog_mode,'destination');assert.equal(sent.fields['/title'].mode,'adapt');assert.equal(sent.fields['/title'].source_path,'');assert.equal(view.calls.length,2);assert(view.host.textContent.includes('Destination preparation saved locally'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('backend description export rejects a changed saved revision before downloading',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=drafts.initialDraft(layout);saved.revision='reviewed-revision';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});view=await editorFixture(saved);
  view.responses.push({draft:{...saved,revision:'someone-elses-revision'},backend_description:{status:'prepared_local',description:{fields:[]}}});await view.button('Export backend description').click();assert.equal(view.calls.length,2);assert.equal(view.calls[1].options.method,'GET');assert(view.host.textContent.includes('The saved draft changed'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('legacy editor switches to the destination sentinel without silently converting bindings',async()=>{
 const originalFetch=global.fetch;let view;
 try{
  const saved=fixture();saved.revision='legacy-revision';saved.catalog_snapshot={...template,...saved.template,authoring_notes:'One relevant label'};view=await editorFixture(saved);view.button('Prepare destination descriptions instead').click();const migrated=view.controller.getDraft();assert.deepEqual(migrated.template,{id:'local-destination',revision:'1'});assert.equal(migrated.catalog_mode,'destination');assert.deepEqual(migrated.fields,saved.fields);assert.deepEqual(migrated.repeat_slots,saved.repeat_slots);assert.equal(migrated.guidance,'One relevant label');assert(!view.control('Delivery field catalog'));assert(!view.control('NOVA source'));assert(view.button('Remove retained legacy repeat bindings after review'));assert(view.button('Backend synchronization unavailable').disabled);
 }finally{view?.controller.destroy();global.fetch=originalFetch;}
});
test('backend description export downloads the saved proposal and refuses unsaved edits',async()=>{
 const originalFetch=global.fetch,originalObjectURL=URL.createObjectURL;let view,exported;
 try{
  URL.createObjectURL=blob=>{exported=blob;return 'blob:local-test';};const saved=drafts.initialDraft(layout);saved.revision='reviewed-revision';saved.fields['/title']=drafts.adaptField({path:'/title',label:'Title'});view=await editorFixture(saved);
  const proposal={status:'prepared_local',local_revision:saved.revision,sha256:'a'.repeat(64),description:{format:'nova-template-description/v1',fields:[{id:'field_title',label:'Title',value_type:'text'}]}};view.responses.push({draft:saved,backend_description:proposal});await view.button('Export backend description').click();assert.deepEqual(JSON.parse(await exported.text()),proposal);assert(view.host.textContent.includes('local preparation'));
  const label=view.control('Destination label');label.value='Changed title';label.events.input();await view.button('Export backend description').click();assert.equal(view.calls.length,2);assert(view.host.textContent.includes('Save and review this destination draft'));
 }finally{view?.controller.destroy();global.fetch=originalFetch;URL.createObjectURL=originalObjectURL;}
});

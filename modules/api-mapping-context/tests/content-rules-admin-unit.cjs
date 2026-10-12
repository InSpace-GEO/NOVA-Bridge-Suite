const { test } = require('node:test');
const assert = require('node:assert/strict');
const editor = require('../assets/content-rules.js');
const preset = {schema_version:1,id:'11111111-1111-4111-8111-111111111111',revision:0,label:'Blog',builder:'elementor',post_type:'page',rules:Object.fromEntries(editor.kinds.map(kind=>[kind.key,{element:kind.elements[0][0],settings:{}}])),layout:{max_width:1140,gap:24},hide_empty:true};
test('local profiles have fresh identities and preserve the preset',()=>{
 const before=structuredClone(preset),first=editor.newProfile(preset),second=editor.newProfile(preset);
 assert.notEqual(first.id,second.id);assert.equal(first.revision,0);assert.deepEqual(preset,before);assert.equal(first.builder,'elementor');
});
test('HTML and explicit nested/FAQ samples stay distinct; malformed structured input is rejected',()=>{
 assert.deepEqual(editor.parseInput('html','<h2>Keep this</h2><p>Text</p>'),{html:'<h2>Keep this</h2><p>Text</p>'});
 assert.deepEqual(editor.parseInput('structured',JSON.stringify(editor.structuredSample)),editor.structuredSample);
 assert.deepEqual(editor.parseInput('structured','[]'),{blocks:[]});
 for(const input of ['{','null','{"html":"not blocks"}'])assert.throws(()=>editor.parseInput('structured',input));
 assert.equal(editor.countBlocks(editor.structuredSample.blocks),10);
});
test('save responses retain edits made during saving and advance the expected revision',()=>{
 const submitted=structuredClone(preset),current=structuredClone(submitted),saved={...submitted,revision:1};current.label='Later edit';
 const merged=editor.mergeSavedProfile(current,submitted,saved);assert.equal(merged.profile.label,'Later edit');assert.equal(merged.profile.revision,1);assert.equal(merged.dirty,true);
 assert.deepEqual(editor.mergeSavedProfile(submitted,submitted,saved),{profile:saved,dirty:false});
});
test('retries keep the same operation; changed content or revision gets a fresh identity',()=>{
 const profile={...preset,revision:1},first=editor.operationIdentity(null,profile,{html:'A'});
 assert.strictEqual(editor.operationIdentity(first,profile,{html:'A'}),first);
 assert.notEqual(editor.operationIdentity(first,profile,{html:'B'}).id,first.id);
 assert.notEqual(editor.operationIdentity(first,{...profile,revision:2},{html:'A'}).id,first.id);
 assert.equal(editor.safeHref('javascript:alert(1)'), '');assert.equal(editor.safeHref('data:text/html,hi'),'');assert.equal(editor.safeHref('https://wp.example/draft','https://wp.example/wp-admin/'),'https://wp.example/draft');assert.equal(editor.safeHref('https://foreign.example/draft','https://wp.example/'),'');
});
test('only an exact verified draft yields same-site links, with WordPress and Elementor editors distinguished',()=>{
 const operation='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',good={post_id:4,status:'draft',operation_uuid:operation,edit_url:'https://wp.example/wp-admin/post.php?post=4',elementor_edit_url:'https://wp.example/wp-admin/post.php?post=4&action=elementor',preview_url:'https://wp.example/?preview=true&p=4'};
 assert.deepEqual(editor.draftResultLinks(good,operation,'https://wp.example/').map(item=>item[1]),['Edit with Elementor','Open WordPress draft','View draft']);
 for(const bad of [null,true,{}, {...good,post_id:'4'},{...good,status:'publish'},{...good,operation_uuid:'different'},{...good,edit_url:'https://foreign.example/editor'}])assert.throws(()=>editor.draftResultLinks(bad,operation,'https://wp.example/'),/did not verify/);
 assert.deepEqual(editor.draftResultLinks({...good,elementor_edit_url:'javascript:bad()',preview_url:'https://foreign.example/view'},operation,'https://wp.example/').map(item=>item[1]),['Open WordPress draft']);assert.equal(editor.normalizedBinding([]),null);
});
test('a page reload reuses a bounded session retry identity without storing article text',async()=>{
 const map=new Map([['unrelated','leave me']]),storage={get length(){return map.size;},key(index){return [...map.keys()][index];},getItem(key){return map.get(key)||null;},setItem(key,value){map.set(key,value);},removeItem(key){map.delete(key);}},profile={...preset,revision:1};
 const first=await editor.rememberedOperation(null,profile,{html:'PRIVATE ARTICLE CONTENT'},storage),reloaded=await editor.rememberedOperation(null,profile,{html:'PRIVATE ARTICLE CONTENT'},storage);assert.equal(first.id,reloaded.id);
 assert([...map.keys()].filter(key=>key.startsWith('nova-content-rule-operation:')).every(key=>/^nova-content-rule-operation:[0-9a-f]{64}$/.test(key)));assert(![...map.values()].join().includes('PRIVATE ARTICLE CONTENT'));
 for(let index=0;index<25;index++)await editor.rememberedOperation(null,profile,{html:'content '+index},storage);assert.equal(map.size,21);assert.equal(map.get('unrelated'),'leave me');
 const restricted={getItem(){throw new Error('Storage denied');}};assert.equal((await editor.rememberedOperation(first,profile,{html:'PRIVATE ARTICLE CONTENT'},restricted)).id,first.id);
});
test('an intentional second draft resets only its own retained operation and remains recoverable',async()=>{
 const map=new Map(),storage={get length(){return map.size;},key(index){return [...map.keys()][index];},getItem(key){return map.get(key)||null;},setItem(key,value){map.set(key,value);},removeItem(key){map.delete(key);}},profile={...preset,revision:1},input={html:'A'};
 const first=await editor.rememberedOperation(null,profile,input,storage),other=await editor.rememberedOperation(null,profile,{html:'B'},storage),next=editor.anotherOperation(first,storage);assert.notEqual(next.id,first.id);assert.equal(map.has(first.storage_key),false);assert.equal(map.has(other.storage_key),true);
 const second=await editor.rememberedOperation(next,profile,input,storage);assert.equal(second.id,next.id);assert.equal((await editor.rememberedOperation(null,profile,input,storage)).id,second.id);assert.equal((await editor.rememberedOperation(second,profile,input,storage)).id,second.id);assert.equal(map.size,2);
});
class Element {
 constructor(tag,document){this.tag=tag;this.ownerDocument=document;this.children=[];this.events={};this.attributes={};this.dataset={};this.value='';this.checked=false;this.disabled=false;this.ownText='';}
 set textContent(value){this.ownText=String(value);this.children=[];}get textContent(){return this.ownText+this.children.map(child=>child.textContent).join(' ');}
 appendChild(child){if(child.parentNode){child.parentNode.children=child.parentNode.children.filter(item=>item!==child);}child.parentNode=this;this.children.push(child);return child;}append(...children){children.forEach(child=>this.appendChild(child));}replaceChildren(...children){this.children=[];this.ownText='';this.append(...children);}
 setAttribute(key,value){this.attributes[key]=value;}addEventListener(key,callback){this.events[key]=callback;}all(){return [this,...this.children.flatMap(child=>child.all())];}
 click(){return this.events.click?.({preventDefault(){}});}
}
async function fixture(profiles=[],siteId='',config={}){
 const document={createElement:tag=>new Element(tag,document)},host=new Element('div',document),calls=[],replies=[];
 global.fetch=async(url,options)=>{calls.push({url,options});const reply=replies.length?replies.shift():{ok:true,value:{profiles,preset,post_types:[{value:'page',label:'Page'},{value:'post',label:'Post'}],connected_site_id:siteId,binding:[]}};return {ok:reply.ok,status:reply.status||200,json:async()=>typeof reply.value==='function'?reply.value(options):reply.value};};
 const controller=editor.mount(host,{url:'/mapping/content-rules',nonce:'nonce',...config});await controller.ready;
 const find=label=>host.all().find(node=>node.tag==='button'&&node.ownText===label);
 const control=label=>host.all().find(node=>node.tag==='label'&&node.children.some(child=>child.ownText===label))?.children.find(node=>['input','select','textarea'].includes(node.tag));
 return {host,calls,replies,controller,find,control};
}
test('offline editor saves locally, permits unsaved preview and protects draft creation until exact save',async()=>{
 const original=global.fetch;try{
  const t=await fixture();assert.equal(t.calls.length,1);assert(t.find('Create Elementor draft').disabled);assert(!t.find('Preview Elementor structure').disabled);assert(t.find('Save delivery preference').disabled);
  assert.equal(t.find('Create another draft').hidden,true);const profile=t.controller.getProfile();t.replies.push({ok:true,value:{preview_document:'<html>Safe server preview</html>',blocks:[{type:'heading',html:'Hello'}],warnings:[]}});await t.find('Preview Elementor structure').click();
  const frame=t.host.all().find(node=>node.tag==='iframe');assert.equal(frame.attributes.sandbox,'');assert.equal(frame.srcdoc,'<html>Safe server preview</html>');assert.equal(t.calls[1].options.headers['X-WP-Nonce'],'nonce');
  t.replies.push({ok:true,value:{...profile,revision:1}});await t.find('Save layout rules').click();assert(!t.find('Create Elementor draft').disabled);assert.equal(t.controller.isDirty(),false);
  t.replies.push({ok:true,value:options=>({post_id:123,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/wp-admin/post.php?post=123',preview_url:'javascript:alert(1)'})});await t.find('Create Elementor draft').click();assert(t.host.textContent.includes('Elementor draft created'));assert.equal(t.host.all().filter(node=>node.tag==='a').length,1);
  assert.equal(t.controller.getView().guided,false);assert(t.host.textContent.includes('Draft #123'));
  const firstOperation=JSON.parse(t.calls.at(-1).options.body).operation_uuid;assert.equal(t.find('Create another draft').hidden,false);t.replies.push({ok:true,value:options=>({post_id:124,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/wp-admin/post.php?post=124'})});await t.find('Create another draft').click();assert.notEqual(JSON.parse(t.calls.at(-1).options.body).operation_uuid,firstOperation);
  assert(t.calls.every(call=>call.url.startsWith('/mapping/content-rules/')));assert(t.calls.every(call=>call.options.credentials==='same-origin'));assert.equal(JSON.parse(t.calls.at(-1).options.body).input.title,'Content layout draft');
 }finally{global.fetch=original;}
});
test('setup links start a fresh guided layout while saved links open the requested workspace',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:2,label:'Existing rules'},second={...saved,id:'33333333-3333-4333-8333-333333333333',label:'Requested rules'},fresh=await fixture([saved], '', {initialView:'setup',newSetup:true});assert.equal(fresh.controller.getView().guided,true);assert.equal(fresh.controller.getView().step,1);assert.notEqual(fresh.controller.getProfile().id,saved.id);assert.equal(fresh.controller.getProfile().revision,0);
  const widgetChoices=fresh.host.all().filter(node=>node.tag==='select'&&node.attributes['aria-label']?.endsWith('Elementor widget'));assert.equal(widgetChoices.length,2);assert(fresh.host.textContent.includes('Three paragraphs → three Text Editor widgets.'));assert(fresh.host.textContent.includes('Content type'));assert(fresh.host.textContent.includes('Elementor widget'));
  const savedView=await fixture([saved,second], '', {initialView:'workspace',profileId:second.id});assert.equal(savedView.controller.getView().guided,false);assert.equal(savedView.controller.getProfile().id,second.id);await savedView.find('Guided setup').click();assert.equal(savedView.controller.getView().guided,true);assert.equal(savedView.controller.getProfile().id,second.id);
  const oldLink=await fixture([saved]);assert.equal(oldLink.controller.getView().guided,true);
 }finally{global.fetch=original;}
});
test('a missing requested saved UUID never silently loads a different layout',async()=>{
 const originalFetch=global.fetch,originalLocation=global.location,originalHistory=global.history,addresses=[],missing='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',available={...preset,revision:3,label:'Available layout'};
 global.location={href:'https://example.invalid/wp-admin/options-general.php?page=nova-settings&tab=content-rules&view=workspace&profile_id='+missing+'&lang=nl'};global.history={replaceState(state,title,url){addresses.push(url);}};
 try{
  const t=await fixture([available], '', {initialView:'workspace',profileId:missing});assert.equal(t.controller.getProfile(),null);assert(t.host.textContent.includes('requested saved layout is not available'));assert(t.find('Save layout rules').disabled);assert(t.find('Create Elementor draft').disabled);assert(t.find('Preview Elementor structure').disabled);assert(t.control('Layout name').disabled);assert.equal(t.control('Saved layout rules').disabled,false);assert.equal(t.find('New layout').disabled,false);assert.equal(addresses.length,0);
  const callCount=t.calls.length;await t.find('Save layout rules').click();await t.find('Create Elementor draft').click();assert.equal(t.calls.length,callCount);assert.equal(addresses.length,0);
  const choose=t.control('Saved layout rules');choose.value=available.id;choose.events.change();assert.equal(t.controller.getProfile().id,available.id);assert(!t.find('Create Elementor draft').disabled);assert.equal(new URL(addresses.at(-1)).searchParams.get('profile_id'),available.id);assert.equal(new URL(addresses.at(-1)).searchParams.get('lang'),'nl');
  const newSetup=await fixture([available], '', {initialView:'setup',profileId:missing,newSetup:true});assert.notEqual(newSetup.controller.getProfile().id,available.id);assert.notEqual(newSetup.controller.getProfile().id,missing);assert.equal(newSetup.controller.getProfile().revision,0);assert(!newSetup.host.textContent.includes('requested saved layout is not available'));
 }finally{global.fetch=originalFetch;global.location=originalLocation;global.history=originalHistory;}
});
test('guided examples can be previewed without opening code editors and heading examples follow the chosen widget',async()=>{
 const original=global.fetch;try{
  const t=await fixture([], '', {initialView:'setup',newSetup:true}),heading=t.host.all().find(node=>node.tag==='select'&&node.attributes['aria-label']==='Article headings Elementor widget');
  assert(t.host.textContent.includes('Two headings → two Heading widgets.'));heading.value='text-editor';heading.events.change();assert(t.host.textContent.includes('Two headings → two Text Editor widgets.'));assert(!t.host.textContent.includes('Two headings → two Heading widgets.'));
  await t.find('Next: try content').click();const ownArticle=t.host.all().find(node=>node.className==='ncr-custom-input'),advanced=t.host.all().find(node=>node.className==='ncr-advanced-input'),summary=t.host.all().find(node=>node.className==='ncr-sample-summary');
  assert.equal(ownArticle.open,false);assert(!advanced.open);assert(summary.textContent.includes('two headings, two paragraphs and one bullet list'));assert.equal(t.find('Article example').attributes['aria-pressed'],'true');
  t.replies.push({ok:true,value:{preview_document:'<html>Safe preview</html>',blocks:[{type:'heading',html:'Article'}],warnings:[]}});await t.find('Preview Elementor structure').click();assert.equal(JSON.parse(t.calls.at(-1).options.body).input.html,editor.sampleHtml);assert.equal(t.controller.getView().previewReady,true);assert.equal(ownArticle.open,false);
  await t.find('FAQs and groups example').click();assert.equal(ownArticle.hidden,true);assert(!advanced.open);assert(summary.textContent.includes('two nested sections and two explicit question-and-answer items'));assert.equal(t.find('FAQs and groups example').attributes['aria-pressed'],'true');
  t.replies.push({ok:true,value:{preview_document:'<html>FAQ preview</html>',blocks:editor.structuredSample.blocks,warnings:[]}});await t.find('Preview Elementor structure').click();assert.deepEqual(JSON.parse(t.calls.at(-1).options.body).input.blocks,editor.structuredSample.blocks);assert.equal(t.controller.getView().previewReady,true);assert(!advanced.open);
  await t.find('Article example').click();ownArticle.open=true;const html=t.control('Article HTML');html.value='<p>My own article</p>';html.events.input();assert(summary.textContent.includes('Your article content is selected'));assert.equal(t.find('Article example').attributes['aria-pressed'],'false');assert.equal(t.controller.getView().previewReady,false);
  const saved={...preset,revision:1},workspace=await fixture([saved], '', {initialView:'workspace'});assert.equal(workspace.host.all().find(node=>node.className==='ncr-custom-input').open,true);await workspace.find('Guided setup').click();assert.equal(workspace.host.all().find(node=>node.className==='ncr-custom-input').open,false);
 }finally{global.fetch=original;}
});
test('the four-step guide previews actual widget plans, saves locally and can finish without creating a draft',async()=>{
 const originalFetch=global.fetch,originalLocation=global.location,originalHistory=global.history,addresses=[];
 global.location={href:'https://example.invalid/wp-admin/options-general.php?page=nova-settings&tab=content-rules&view=setup&new=1&lang=nl'};global.history={replaceState(state,title,url){addresses.push(url);}};
 try{
  const t=await fixture([], '', {initialView:'setup',newSetup:true});await t.find('Next: try content').click();assert.equal(t.controller.getView().step,2);assert(t.find('Next: review rules').disabled);
  await t.find('FAQs and groups example').click();assert.equal(t.host.all().filter(node=>node.tag==='textarea').length,1);const advanced=t.host.all().find(node=>node.className==='ncr-advanced-input');assert(!advanced.open);
  const summary={widget_count:7,containers:3,total_elements:10,widgets:[{type:'heading',label:'Elementor Heading',count:3},{type:'text-editor',label:'Elementor Text Editor',count:3},{type:'accordion',label:'Elementor Accordion',count:1}]};t.replies.push({ok:true,value:{preview_document:'<html>server preview</html>',blocks:editor.structuredSample.blocks,warnings:[],element_summary:summary}});await t.find('Preview Elementor structure').click();assert(!t.find('Next: review rules').disabled);assert(t.host.textContent.includes('7 widgets'));assert(t.host.textContent.includes('3 containers'));assert(!t.find('Preview Elementor structure').className.includes('button-primary'));assert(t.find('Next: review rules').className.includes('button-primary'));
  await t.find('Next: review rules').click();assert.equal(t.controller.getView().step,3);assert(t.find('Next: create a draft').disabled);const name=t.control('Layout name');name.value='My branded article';name.events.input();assert(t.host.textContent.includes('does not create a page'));assert.equal(t.controller.getView().previewReady,true);const type=t.control('Create as');type.value='post';type.events.change();assert.equal(t.controller.getView().previewReady,true);assert(t.host.all().find(node=>node.className==='ncr-review-summary').textContent.includes('7 planned widgets'));
  const saved={...t.controller.getProfile(),revision:1};t.replies.push({ok:true,value:saved});await t.find('Save layout rules').click();assert.equal(t.controller.getView().step,4);assert.equal(t.controller.isDirty(),false);assert(t.find('Save layout rules').disabled);assert.equal(addresses.length,0);assert.equal(t.controller.getView().previewReady,true);const plan=t.host.all().find(node=>node.className==='ncr-widget-summary ncr-draft-plan');assert(plan.textContent.includes('7 planned widgets'));const title=t.control('Draft title');title.value='My sample post';title.events.input();assert.equal(t.controller.getView().previewReady,true);assert(plan.textContent.includes('7 planned widgets'));
  await t.find('Finish setup without a draft').click();assert.equal(t.controller.getView().guided,false);assert(t.host.textContent.includes('No draft was created'));assert(!t.calls.some(call=>call.url.endsWith('/create-draft')));const address=new URL(addresses.at(-1));assert.equal(address.origin,'https://example.invalid');assert.equal(address.searchParams.get('view'),'workspace');assert.equal(address.searchParams.get('profile_id'),saved.id);assert.equal(address.searchParams.get('lang'),'nl');assert.equal(address.searchParams.has('new'),false);
  await t.find('Guided setup').click();assert.equal(t.controller.getView().guided,true);assert.equal(t.controller.getProfile().id,saved.id);assert.equal(t.controller.getProfile().revision,1);
 }finally{global.fetch=originalFetch;global.location=originalLocation;global.history=originalHistory;}
});
test('the guide stepper cannot bypass current preview and saved-revision review',async()=>{
 const original=global.fetch;try{
  const t=await fixture([], '', {initialView:'setup',newSetup:true}),review=t.find('3. Review and save'),create=t.find('4. Create a draft');assert(review.disabled);assert(create.disabled);await review.click();await create.click();assert.equal(t.controller.getView().step,1);assert(t.find('Open workspace').disabled);assert(t.host.textContent.includes('Page is a website page; Post is a blog article'));
  await t.find('Next: try content').click();await review.click();assert.equal(t.controller.getView().step,2);const calls=t.calls.length;await t.find('Create Elementor draft').click();assert.equal(t.calls.length,calls);
  t.replies.push({ok:true,value:{preview_document:'<html>preview</html>',blocks:[{type:'paragraph',html:'Text'}],warnings:[]}});await t.find('Preview Elementor structure').click();assert(!review.disabled);assert(create.disabled);await review.click();assert.equal(t.controller.getView().step,3);await create.click();assert.equal(t.controller.getView().step,3);
  t.replies.push({ok:true,value:{...t.controller.getProfile(),revision:1}});await t.find('Save layout rules').click();assert.equal(t.controller.getView().step,4);assert(!create.disabled);assert(!t.find('Create Elementor draft').disabled);assert(!t.find('Finish setup without a draft').disabled);
  await t.find('1. Rules').click();const font=t.control('Font size (px)');font.value='28';font.events.input();assert(review.disabled);assert(create.disabled);await create.click();assert.equal(t.controller.getView().step,1);assert(!t.find('2. Try content').disabled);
  const saved={...preset,revision:2},advanced=await fixture([saved],'',{initialView:'setup'});assert(advanced.find('Create Elementor draft').disabled);assert(!advanced.find('Open workspace').disabled);await advanced.find('Open workspace').click();assert.equal(advanced.controller.getView().guided,false);assert(!advanced.find('Create Elementor draft').disabled);
 }finally{global.fetch=original;}
});
test('render-affecting newer edits during save invalidate the plan and remain unsaved',async()=>{
 const original=global.fetch;try{
  const t=await fixture(),summary={widget_count:1,containers:1,total_elements:2,widgets:[{type:'heading',label:'Elementor Heading',count:1}]};t.replies.push({ok:true,value:{preview_document:'<html>heading</html>',blocks:[{type:'heading',level:2,html:'Heading'}],warnings:[],element_summary:summary}});await t.find('Preview Elementor structure').click();assert.equal(t.controller.getView().previewReady,true);
  const submitted=t.controller.getProfile();let resolve;t.replies.push({ok:true,value:()=>new Promise(done=>{resolve=done;})});const pending=t.find('Save layout rules').click();await new Promise(done=>setImmediate(done));assert.equal(typeof resolve,'function');const font=t.control('Font size (px)');font.value='36';font.events.input();assert.equal(t.controller.getView().previewReady,false);resolve({...submitted,revision:1});await pending;
  assert.equal(t.controller.getProfile().rules.heading.settings.typography_font_size,36);assert.equal(t.controller.getProfile().revision,1);assert.equal(t.controller.isDirty(),true);assert.equal(t.controller.getView().previewReady,false);assert(!t.host.all().find(node=>node.className==='ncr-widget-summary ncr-draft-plan').textContent.includes('1 planned widgets'));assert(t.find('Create Elementor draft').disabled);assert(t.host.textContent.includes('newer edits are still unsaved'));
 }finally{global.fetch=original;}
});
test('older preview APIs fall back to recognized content without inventing native widget counts',async()=>{
 const original=global.fetch;try{const t=await fixture();t.replies.push({ok:true,value:{preview_document:'<html>body</html>',blocks:[{type:'paragraph',html:'Text'}],warnings:[]}});await t.find('Preview Elementor structure').click();assert(t.host.textContent.includes('Recognized content'));assert(t.host.textContent.includes('Native element counts are available in the created draft'));assert.equal(editor.elementSummary({widget_count:-1,containers:1,total_elements:0,widgets:[]}),null);}finally{global.fetch=original;}
});
test('custom post types remain usable for local drafts but cannot opt in to fetched deliveries',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:1,post_type:'book'},t=await fixture([saved],'22222222-2222-4222-8222-222222222222',{initialView:'workspace'}),choice=t.control('Use these saved rules for new, unmapped deliveries');assert(!t.find('Create Elementor draft').disabled);assert(t.host.textContent.includes('cannot be selected for fetched deliveries'));
  choice.checked=true;choice.events.change();assert(t.find('Save delivery preference').disabled);choice.checked=false;choice.events.change();assert(!t.find('Save delivery preference').disabled);
 }finally{global.fetch=original;}
});
test('conflicts preserve editor changes and an uncertain create retries its retained operation',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:1},t=await fixture([saved],'',{initialView:'workspace'}),label=t.control('Layout name');label.value='Keep my edit';label.events.input();
  t.replies.push({ok:false,status:409,value:{message:'This profile changed elsewhere.'}},{ok:true,value:{profiles:[{...saved,revision:2,label:'Remote edit'}]}});await t.find('Save layout rules').click();assert.equal(t.controller.getProfile().label,'Keep my edit');assert(t.controller.isDirty());assert(t.host.textContent.includes('Your edits are retained'));assert.equal(t.find('Load latest saved revision').hidden,false);assert.equal(t.calls.at(-1).options.method,'GET');
  await t.find('Load latest saved revision').click();assert.equal(t.controller.getProfile().label,'Remote edit');assert.equal(t.controller.getProfile().revision,2);assert.equal(t.controller.isDirty(),false);
  t.replies.push({ok:false,status:503,value:{message:'Commit unknown'}});await t.find('Create Elementor draft').click();const first=JSON.parse(t.calls.at(-1).options.body).operation_uuid;
  await t.find('Guided setup').click();await t.find('Open workspace').click();assert(t.host.textContent.includes('draft attempt is unverified'));assert(!t.host.textContent.includes('No draft was created by this setup'));
  t.replies.push({ok:true,value:options=>({post_id:7,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/edit'})});await t.find('Create Elementor draft').click();assert.equal(JSON.parse(t.calls.at(-1).options.body).operation_uuid,first);
 }finally{global.fetch=original;}
});
test('delivery preference is explicit and a boolean binding response retains the exact selected revision',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:3},site='22222222-2222-4222-8222-222222222222',t=await fixture([saved],site),choice=t.control('Use these saved rules for new, unmapped deliveries');
  assert.equal(choice.checked,false);assert(!t.find('Save delivery preference').disabled);assert.equal(t.calls.length,2);assert(t.host.textContent.includes('Automatic use of local rules is off.'));assert(!t.host.textContent.includes('undefined'));
  choice.checked=true;t.replies.push({ok:true,value:true});await t.find('Save delivery preference').click();
  const body=JSON.parse(t.calls.at(-1).options.body);assert.deepEqual(body,{site_id:site,enabled:true,profile_id:saved.id,profile_revision:3});
  const selector=t.control('Saved layout rules');selector.value=saved.id;selector.events.change();assert.equal(choice.checked,true);
  const label=t.control('Layout name');label.value='Revised profile';label.events.input();t.replies.push({ok:true,value:{...t.controller.getProfile(),revision:4}});await t.find('Save layout rules').click();assert.equal(choice.checked,false);assert(t.host.textContent.includes('revision 3'));assert(t.host.textContent.includes('does not replace'));
  choice.checked=false;t.replies.push({ok:true,value:true});await t.find('Save delivery preference').click();selector.events.change();assert.equal(choice.checked,false);
 }finally{global.fetch=original;}
});

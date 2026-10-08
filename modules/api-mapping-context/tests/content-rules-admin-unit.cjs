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
 constructor(tag,document){this.tag=tag;this.ownerDocument=document;this.children=[];this.events={};this.attributes={};this.value='';this.checked=false;this.disabled=false;this.ownText='';}
 set textContent(value){this.ownText=String(value);this.children=[];}get textContent(){return this.ownText+this.children.map(child=>child.textContent).join(' ');}
 appendChild(child){child.parentNode=this;this.children.push(child);return child;}append(...children){children.forEach(child=>this.appendChild(child));}replaceChildren(...children){this.children=[];this.ownText='';this.append(...children);}
 setAttribute(key,value){this.attributes[key]=value;}addEventListener(key,callback){this.events[key]=callback;}all(){return [this,...this.children.flatMap(child=>child.all())];}
 click(){return this.events.click?.({preventDefault(){}});}
}
async function fixture(profiles=[],siteId=''){
 const document={createElement:tag=>new Element(tag,document)},host=new Element('div',document),calls=[],replies=[];
 global.fetch=async(url,options)=>{calls.push({url,options});const reply=replies.length?replies.shift():{ok:true,value:{profiles,preset,post_types:[{value:'page',label:'Page'},{value:'post',label:'Post'}],connected_site_id:siteId,binding:[]}};return {ok:reply.ok,status:reply.status||200,json:async()=>typeof reply.value==='function'?reply.value(options):reply.value};};
 const controller=editor.mount(host,{url:'/mapping/content-rules',nonce:'nonce'});await controller.ready;
 const find=label=>host.all().find(node=>node.tag==='button'&&node.ownText===label);
 const control=label=>host.all().find(node=>node.tag==='label'&&node.children.some(child=>child.ownText===label))?.children.find(node=>['input','select','textarea'].includes(node.tag));
 return {host,calls,replies,controller,find,control};
}
test('offline editor saves locally, permits unsaved preview and protects draft creation until exact save',async()=>{
 const original=global.fetch;try{
  const t=await fixture();assert.equal(t.calls.length,1);assert(t.find('Create Elementor draft').disabled);assert(!t.find('Update preview').disabled);assert(t.find('Save delivery preference').disabled);
  assert.equal(t.find('Create another draft').hidden,true);const profile=t.controller.getProfile();t.replies.push({ok:true,value:{preview_document:'<html>Safe server preview</html>',blocks:[{type:'heading',html:'Hello'}],warnings:[]}});await t.find('Update preview').click();
  const frame=t.host.all().find(node=>node.tag==='iframe');assert.equal(frame.attributes.sandbox,'');assert.equal(frame.srcdoc,'<html>Safe server preview</html>');assert.equal(t.calls[1].options.headers['X-WP-Nonce'],'nonce');
  t.replies.push({ok:true,value:{...profile,revision:1}});await t.find('Save local profile').click();assert(!t.find('Create Elementor draft').disabled);assert.equal(t.controller.isDirty(),false);
  t.replies.push({ok:true,value:options=>({post_id:123,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/wp-admin/post.php?post=123',preview_url:'javascript:alert(1)'})});await t.find('Create Elementor draft').click();assert(t.host.textContent.includes('Elementor draft created'));assert.equal(t.host.all().filter(node=>node.tag==='a').length,1);
  const firstOperation=JSON.parse(t.calls.at(-1).options.body).operation_uuid;assert.equal(t.find('Create another draft').hidden,false);t.replies.push({ok:true,value:options=>({post_id:124,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/wp-admin/post.php?post=124'})});await t.find('Create another draft').click();assert.notEqual(JSON.parse(t.calls.at(-1).options.body).operation_uuid,firstOperation);
  assert(t.calls.every(call=>call.url.startsWith('/mapping/content-rules/')));assert(t.calls.every(call=>call.options.credentials==='same-origin'));assert.equal(JSON.parse(t.calls.at(-1).options.body).input.title,'Content layout draft');
 }finally{global.fetch=original;}
});
test('custom post types remain usable for local drafts but cannot opt in to fetched deliveries',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:1,post_type:'book'},t=await fixture([saved],'22222222-2222-4222-8222-222222222222'),choice=t.control('Use this saved profile for unconfigured deliveries');assert(!t.find('Create Elementor draft').disabled);assert(t.host.textContent.includes('cannot be selected for fetched deliveries'));
  choice.checked=true;choice.events.change();assert(t.find('Save delivery preference').disabled);choice.checked=false;choice.events.change();assert(!t.find('Save delivery preference').disabled);
 }finally{global.fetch=original;}
});
test('conflicts preserve editor changes and an uncertain create retries its retained operation',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:1},t=await fixture([saved]),label=t.control('Profile name');label.value='Keep my edit';label.events.input();
  t.replies.push({ok:false,status:409,value:{message:'This profile changed elsewhere.'}},{ok:true,value:{profiles:[{...saved,revision:2,label:'Remote edit'}]}});await t.find('Save local profile').click();assert.equal(t.controller.getProfile().label,'Keep my edit');assert(t.controller.isDirty());assert(t.host.textContent.includes('Your edits are retained'));assert.equal(t.find('Load latest saved revision').hidden,false);assert.equal(t.calls.at(-1).options.method,'GET');
  await t.find('Load latest saved revision').click();assert.equal(t.controller.getProfile().label,'Remote edit');assert.equal(t.controller.getProfile().revision,2);assert.equal(t.controller.isDirty(),false);
  t.replies.push({ok:false,status:503,value:{message:'Commit unknown'}});await t.find('Create Elementor draft').click();const first=JSON.parse(t.calls.at(-1).options.body).operation_uuid;
  t.replies.push({ok:true,value:options=>({post_id:7,status:'draft',operation_uuid:JSON.parse(options.body).operation_uuid,edit_url:'https://example.invalid/edit'})});await t.find('Create Elementor draft').click();assert.equal(JSON.parse(t.calls.at(-1).options.body).operation_uuid,first);
 }finally{global.fetch=original;}
});
test('delivery preference is explicit and a boolean binding response retains the exact selected revision',async()=>{
 const original=global.fetch;try{
  const saved={...preset,revision:3},site='22222222-2222-4222-8222-222222222222',t=await fixture([saved],site),choice=t.control('Use this saved profile for unconfigured deliveries');
  assert.equal(choice.checked,false);assert(!t.find('Save delivery preference').disabled);assert.equal(t.calls.length,2);assert(t.host.textContent.includes('Automatic use of local rules is off.'));assert(!t.host.textContent.includes('undefined'));
  choice.checked=true;t.replies.push({ok:true,value:true});await t.find('Save delivery preference').click();
  const body=JSON.parse(t.calls.at(-1).options.body);assert.deepEqual(body,{site_id:site,enabled:true,profile_id:saved.id,profile_revision:3});
  const selector=t.control('Local profile');selector.value=saved.id;selector.events.change();assert.equal(choice.checked,true);
  const label=t.control('Profile name');label.value='Revised profile';label.events.input();t.replies.push({ok:true,value:{...t.controller.getProfile(),revision:4}});await t.find('Save local profile').click();assert.equal(choice.checked,false);assert(t.host.textContent.includes('revision 3'));assert(t.host.textContent.includes('does not replace'));
  choice.checked=false;t.replies.push({ok:true,value:true});await t.find('Save delivery preference').click();selector.events.change();assert.equal(choice.checked,false);
 }finally{global.fetch=original;}
});

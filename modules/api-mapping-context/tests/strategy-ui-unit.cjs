// Run with node. Exercises production grouping and scope logic without WordPress.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../assets/strategy-admin.js', 'utf8');
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
const storage = new Map();
const context = { state: {scope:'strategy',data:{layouts:[],unresolved:[]},epoch:0}, scopeChosen:false, config:{mappingUrl:'https://example.test/mapping'}, localStorage:{setItem:(k,v)=>storage.set(k,v)}, root:{setAttribute(){},removeAttribute(){}}, render(){}, notice(){}, Map, Set };
vm.createContext(context);
vm.runInContext(section('function arr(', 'function button(') + section('function setScope(', 'async function action(') + section('function entries()', 'function statusText(') + section('function guidedVisibility(', 'function render()'), context);
const visibility=step=>JSON.parse(JSON.stringify(context.guidedVisibility({view:'setup',step})));
assert.deepEqual(visibility(1),{workspace:false,sidebar:true,referencePicker:true,referenceSummary:false,preview:true,technical:false});
assert.deepEqual(visibility(2),{workspace:false,sidebar:false,referencePicker:false,referenceSummary:true,preview:true,technical:false},'Mapping keeps the preview beside one field while reference and strategy tools stay hidden');
for(const step of [3,4])assert.deepEqual(visibility(step),{workspace:false,sidebar:false,referencePicker:false,referenceSummary:true,preview:false,technical:false});
assert.deepEqual(JSON.parse(JSON.stringify(context.guidedVisibility({view:'workspace',step:4}))),{workspace:true,sidebar:true,referencePicker:true,referenceSummary:false,preview:true,technical:true});
assert(!source.includes('Hide cookie'),'Consent checkbox is absent from the focused flow');assert(source.includes('hideConsent: true'),'Every refreshed preview handshake requests banner hiding');
function row(id, extra={}) { return {id,path:'/banden-kopen/'+id+'/',parent_path:'/banden-kopen/',status:'needs_reference',basis:'Choose an example',candidates:[{signature:'a'},{signature:'b'}],...extra}; }
context.state.data.unresolved=[row('den-haag'),row('zwolle',{candidates:[{signature:'b'},{signature:'a'}]}),row('en',{locale:'en'}),row('blog',{page_type:'blog'}),row('different',{candidates:[{signature:'c'}]}),row('root',{parent_path:'/'}),row('assigned',{reference_id:12}),row('other',{parent_path:'/weblog/'})];
let groups=context.entries(); assert.equal(groups.length,7); assert.equal(groups[0].rows.length,2); assert.equal(groups[0].rows[1].id,'zwolle');
context.state.scope='all'; assert.equal(context.entries().length,0);
const grouped={key:'shared-layout',unresolved:false,rows:[],layout:{title:'NOVA staging rule editor test',path:'/nova-staging-rule-editor-test/',post_type:'page',builders:['elementor'],reference_id:5264,members:[{reference_type:'post',reference_id:5264,title:'NOVA staging rule editor test',path:'/nova-staging-rule-editor-test/'},{reference_type:'post',reference_id:5267,title:'NOVA onboarding staging demo',path:'/nova-onboarding-staging-demo/'}]}};
const originalGroup=JSON.stringify(grouped),search=query=>context.layoutSearch(grouped,query);
for(const query of ['NOVA onboarding staging demo','onboarding-staging-demo','5267','post:5267']){const match=search(query);assert.equal(match.visible,true);assert.equal(match.label,'NOVA onboarding staging demo');assert.equal(match.reference.reference_id,5267,'A unique member match chooses exactly the reference named in the button');}
assert.equal(search('staging').visible,true);assert.equal(search('staging').reference,null,'Ambiguous member searches preserve the group default and its reference selector');assert.equal(search('staging').label,'NOVA staging rule editor test');
assert.equal(search('').reference,null);assert.equal(search('').label,'NOVA staging rule editor test');assert.equal(search('not present').visible,false);assert.equal(search('5264').reference.reference_id,5264);assert.equal(JSON.stringify(grouped),originalGroup,'Searching never mutates inventory or a saved layout');
(async()=>{
 let calls=[]; context.api=async q=>{calls.push(q);return {summary:{strategy_urls:15}};};
 context.scopeChosen=false; await context.refresh(); assert.equal(context.state.scope,'strategy'); assert.equal(calls.length,2); assert.equal(storage.get('nova-mapping-scope:https://example.test/mapping'),'strategy');
 calls=[]; context.setScope('all'); await context.refresh(); assert.equal(context.state.scope,'all'); assert.equal(calls.length,1);
 context.scopeChosen=false; context.state.scope='all'; context.api=async()=>({summary:{strategy_urls:0}}); await context.refresh(); assert.equal(context.state.scope,'all');
 context.config.initialReference={reference_type:'post',reference_id:42};context.state.initialReferenceApplied=false;context.selectInitialReference({layouts:[{signature:'fresh-layout',members:[{reference_type:'post',reference_id:41},{reference_type:'post',reference_id:42}]}]});assert.equal(context.state.selected,'fresh-layout');assert.equal(context.state.nextReference.reference_id,42);
 context.state.initialReferenceApplied=false;context.selectInitialReference({layouts:[]});assert.equal(context.state.selected,'missing-saved-reference');assert.equal(context.state.referenceMissing,true,'A missing saved reference must not silently target another page');
 assert.equal(context.state.initialReferenceApplied,false,'An absent saved reference stays eligible for a later verified retry');assert.equal(context.state.nextReference,null);
 calls=[];context.scopeChosen=false;context.state.scope='strategy';context.api=async q=>{calls.push(q);return {summary:{strategy_urls:15},layouts:q==='?scope=all'?[{signature:'outside-imported-strategy',members:[{reference_type:'post',reference_id:42}]}]:[{signature:'imported-only',members:[{reference_type:'post',reference_id:99}]}]};};await context.refresh();assert.deepEqual(calls,['?scope=all']);assert.equal(context.state.selected,'outside-imported-strategy');assert.equal(context.state.nextReference.reference_id,42);assert.equal(context.state.referenceMissing,false,'The actual saved reference resolves despite unrelated strategy imports');
 context.state.initialReferenceApplied=false;context.api=async()=>({summary:{strategy_urls:15},layouts:[]});await context.refresh();assert.equal(context.state.selected,'missing-saved-reference');assert.equal(context.state.initialReferenceApplied,false);
 context.api=async()=>({summary:{strategy_urls:15},layouts:[{signature:'restored-reference',members:[{reference_type:'post',reference_id:42}]}]});await context.refresh();assert.equal(context.state.selected,'restored-reference');assert.equal(context.state.referenceMissing,false,'Refreshing can recover a reference that was temporarily unavailable');
 console.log('PASS sibling grouping, distinct hints/candidates, all-site scope, import default and explicit scope preservation.');
})().catch(e=>{console.error(e);process.exitCode=1;});

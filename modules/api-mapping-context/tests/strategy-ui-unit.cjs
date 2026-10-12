// Run with node. Exercises production grouping and scope logic without WordPress.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../assets/strategy-admin.js', 'utf8');
function section(start, end) { return source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start))); }
const storage = new Map();
const context = { state: {scope:'strategy',data:{layouts:[],unresolved:[]},epoch:0}, scopeChosen:false, config:{mappingUrl:'https://example.test/mapping'}, localStorage:{setItem:(k,v)=>storage.set(k,v)}, root:{setAttribute(){},removeAttribute(){}}, render(){}, notice(){}, Map, Set };
vm.createContext(context);
vm.runInContext(section('function arr(', 'function button(') + section('function setScope(', 'async function action(') + section('function entries()', 'function statusText('), context);
function row(id, extra={}) { return {id,path:'/banden-kopen/'+id+'/',parent_path:'/banden-kopen/',status:'needs_reference',basis:'Choose an example',candidates:[{signature:'a'},{signature:'b'}],...extra}; }
context.state.data.unresolved=[row('den-haag'),row('zwolle',{candidates:[{signature:'b'},{signature:'a'}]}),row('en',{locale:'en'}),row('blog',{page_type:'blog'}),row('different',{candidates:[{signature:'c'}]}),row('root',{parent_path:'/'}),row('assigned',{reference_id:12}),row('other',{parent_path:'/weblog/'})];
let groups=context.entries(); assert.equal(groups.length,7); assert.equal(groups[0].rows.length,2); assert.equal(groups[0].rows[1].id,'zwolle');
context.state.scope='all'; assert.equal(context.entries().length,0);
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

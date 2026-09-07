const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/strategy-admin.js','utf8');
const c={selectedRows:new Set(['future-a']),state:{},arr:v=>Array.isArray(v)?v:[],canLeave:()=>true,notice(){},calls:[]};
c.api=async(route,body)=>{c.calls.push({route,body});return {rows:[{id:'future-b',signature:'old'},{id:'future-a',signature:'replacement'}]};};
c.action=fn=>{c.pending=fn();};vm.createContext(c);
vm.runInContext(source.split('\n').find(line=>line.includes('function assign( refType, refId )')),c);
(async()=>{
 c.assign('post',42);await c.pending;
 assert.deepEqual(JSON.parse(JSON.stringify(c.calls)),[{route:'/assign',body:{row_ids:['future-a'],reference_type:'post',reference_id:42}}]);
 assert.equal(c.state.selected,'replacement');assert.equal(c.state.nextReference.reference_id,42);
 c.canLeave=()=>false;c.assign('post',43);assert.equal(c.calls.length,1,'Cancelled unsaved changes must prevent assignment');
 c.canLeave=()=>true;c.selectedRows.clear();c.assign('post',43);assert.equal(c.calls.length,1,'Empty selection must not assign the group');
 console.log('PASS selected URL replacement, navigation to new reference, unsaved cancellation and empty selection');
})().catch(e=>{console.error(e);process.exitCode=1;});

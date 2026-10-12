const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../assets/mapping-preview.js', 'utf8');
let nodes = [];
const context = {document:{querySelectorAll:()=>nodes}, visible:n=>!n.hidden};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function target('),source.indexOf('function bind(')),context);
const field={source:'acf',preview_text:'Distinct article content'};
const leaf={textContent:field.preview_text,closest:()=>null,contains:()=>false};
const wrapper={...leaf,contains:n=>n===leaf};
nodes=[wrapper,leaf]; assert.equal(context.target(field).node,leaf);
assert.equal(context.target(field).precision,'text-match');
nodes=[leaf,{...leaf}]; assert.equal(context.target(field),null,'Repeated copy must not guess a location');
nodes=[{...leaf,hidden:true}]; assert.equal(context.target(field),null);
nodes=[{...leaf,closest:()=>({})}]; assert.equal(context.target(field),null,'Navigation and form content must not bind');
nodes=[leaf]; assert.equal(context.target({...field,preview_text:'Short'}),null);
console.log('PASS ACF unique text matching, smallest region, ambiguity, hidden and excluded content');

nodes=[{...leaf,textContent:'Home'}]; assert.equal(context.target({source:'native',path:'/title',preview_text:'Home'}).node,nodes[0]);

// Run the complete iframe script: CSS is ready before consent markup exists, and
// class/ID changes restore scrolling without changing cookies or rebinding fields.
function consentPreview(bannerInitially=false) {
 const classes=new Set(),styles=[],messages=[],listeners={},timers=new Map();let banner=bannerInitially,observer,observed,timerId=0;
 const document={readyState:'complete',body:{},head:{appendChild(node){styles.push(node);}},documentElement:{classList:{add(name){classes.add(name);},toggle(name,present){if(present){classes.add(name);}else{classes.delete(name);}}}},querySelector(){return banner?{}:null;},querySelectorAll(){return [];},createElement(){return {textContent:''};},addEventListener(){}};
 Object.defineProperty(document,'cookie',{set(){throw new Error('Preview must not write consent cookies');}});
 const parent={postMessage(message,origin){messages.push({message,origin});}},window={NovaMappingPreview:{referenceId:12,referenceType:'post',parentOrigin:'https://example.invalid'},parent,addEventListener(type,callback){listeners[type]=callback;}};
 const runtime={window,document,MutationObserver:function(callback){observer=callback;this.observe=(target,options)=>{observed={target,options};};},setTimeout(callback){const id=++timerId;timers.set(id,callback);return id;},clearTimeout(id){timers.delete(id);},getComputedStyle(){return {visibility:'visible'};}};
 vm.runInNewContext(source,runtime);
 return {classes,styles,messages,document,observed,setBanner(value){banner=value;},change(type,attributeName='class'){observer([{type,attributeName}]);},flush(){const pending=[...timers.values()];timers.clear();pending.forEach(callback=>callback());},message(data){listeners.message({source:parent,origin:'https://example.invalid',data:{source:'nova-mapping-admin',referenceId:12,...data}});}};
}
const automatic=consentPreview();assert(automatic.classes.has('nova-preview-hide-consent'),'Known-banner CSS must be active even before markup exists');assert(!automatic.classes.has('nova-preview-consent-present'),'Normal preview scrolling must stay unchanged when no known banner is present');assert(automatic.styles[0].textContent.includes('html.nova-preview-hide-consent #cookiebanner'));assert(automatic.styles[0].textContent.includes('html.nova-preview-consent-present body{overflow:auto!important}'));
assert.equal(automatic.observed.target,automatic.document.body);assert.deepEqual(Array.from(automatic.observed.options.attributeFilter), ['class','id']);const bindingsBefore=automatic.messages.filter(item=>item.message.type==='bindings').length;
automatic.setBanner(true);automatic.change('attributes');automatic.flush();assert(automatic.classes.has('nova-preview-consent-present'),'A late-classified known banner must restore document scrolling');assert.equal(automatic.messages.filter(item=>item.message.type==='bindings').length,bindingsBefore,'Highlight/attribute mutations must not cause rebind loops');
automatic.message({type:'bind',fields:[],hideConsent:false});automatic.message({type:'consent',hidden:false});assert(automatic.classes.has('nova-preview-hide-consent'),'Legacy messages cannot disable automatic preview hiding');
automatic.setBanner(false);automatic.change('attributes');automatic.flush();assert(!automatic.classes.has('nova-preview-consent-present'));assert(automatic.classes.has('nova-preview-hide-consent'),'Hiding CSS stays ready after a banner is removed');
automatic.setBanner(true);automatic.change('attributes','id');automatic.flush();assert(automatic.classes.has('nova-preview-consent-present'),'A known banner classified through a late ID also restores scrolling');automatic.setBanner(false);automatic.change('attributes','id');automatic.flush();assert(!automatic.classes.has('nova-preview-consent-present'));
automatic.setBanner(true);automatic.change('childList');automatic.flush();assert(automatic.classes.has('nova-preview-consent-present'));assert(automatic.messages.filter(item=>item.message.type==='bindings').length>bindingsBefore,'Inserted content must still refresh visual bindings');
const refreshed=consentPreview(true);assert(refreshed.classes.has('nova-preview-hide-consent'));assert(refreshed.classes.has('nova-preview-consent-present'),'A new preview document must hide known banners without a checkbox or parent opt-in');
console.log('PASS automatic known-banner hiding, late class/ID detection, refresh, scrolling and no consent writes');

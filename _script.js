/* ============================================================
 * _script.js — Kabamo Digital v3
 * Page-aware (element-existence guards) so one script serves all pages.
 *
 * Carries forward the v2 "coupled machine": choosing a value chain sets the
 * phone's role colour; Run dials *384# on the real phone widget; each step
 * sends ONE keypress to the live ussd.php AND lights the flow-chart AND
 * narrates it. Same household re-dials => the record updates.
 *
 * Dashboard rendering matches dashboard.php's JSON exactly:
 *   register{}, freshness{fresh,due}, spaces_distribution[], chains{},
 *   regions[]{name,coverage,reported,expected,spaces,status}, seeded(int),
 *   activity{recent[]{hh,spaces,round,at}}, generated_at, k_anon.
 * ============================================================ */
(function(){
"use strict";
const $ = id => document.getElementById(id);
function fmt(n){ return (n==null) ? "—" : Number(n).toLocaleString(); }

/* ================= ROLES (drive the phone↔process colour coupling) ===== */
const SIM_ROLES = {
  household: {name:"Household",   short:"Register",   color:"#C76B86"},  // warm (softened)
  monitor:   {name:"Monitor",     short:"Protect",    color:"#9A5497"},  // warm
  store:     {name:"Storekeeper", short:"Supply",     color:"#3E6BB0"},  // cool (matches --blue)
  lastmile:  {name:"Last-mile",   short:"Distribute", color:"#2E8B81"},  // cool (matches --teal)
  allocator: {name:"Allocator",   short:"Quantify",   color:"#6E5F5C"},  // neutral
};

/* ================= LIVE PHONE WIDGET ================= */
let simRoleKey="household";
let simSession="web-"+Math.random().toString(36).slice(2,9);
let simText="";       // accumulated AT keypresses joined by '*'
let simBuf="";        // dial string being typed at level 0
let simOpen=false;    // is a USSD session open?
let simPhoneNum=null, simLastPhoneNum=null, simReuseCaller=false;

function newTzNumber(){ let n="+2557"; for(let i=0;i<8;i++) n+=Math.floor(Math.random()*10); return n; }
function maskNum(n){ return !n ? "—" : n.slice(0,5)+" "+n[5]+"•• ••• •"+n.slice(-3); }
function setCallerLabel(){
  const el=$('callerLabel'); if(!el) return;
  el.innerHTML = simPhoneNum ? "this caller: <b>"+maskNum(simPhoneNum)+"</b>" : "no active caller";
}
function simChrome(){
  const r=SIM_ROLES[simRoleKey], tag=$('simTag'); if(!tag) return;
  tag.textContent=r.name+" · "+r.short; tag.style.background=r.color;
  const ph=$('simPhone'); ph.style.borderColor=r.color;
  ph.style.boxShadow="0 0 0 1px "+r.color+"44, 0 14px 34px rgba(16,20,27,.28)";
}
function simScreen(t,b){ const a=$('simScrTitle'),c=$('simScrBody'); if(a)a.textContent=t; if(c)c.textContent=b; }
function simShowDial(){ const d=$('simDial'); if(d) d.textContent = simOpen ? simText.replaceAll('*',' · ') : simBuf; }

function simPress(ch){
  if(!simOpen){ simBuf+=ch; simShowDial(); if(ch==='#') simSend(); return; }
  simText = simText==="" ? ch : (simText+"*"+ch);
  simShowDial(); simSend();
}
function simBack(){ if(!simOpen){ simBuf=simBuf.slice(0,-1); simShowDial(); } }

async function simSend(){
  if(!simOpen){
    if(simBuf.trim()!=="*384#"){
      simScreen("Dial to start","Dial *384# to open the menu.\nPress * 3 8 4 #");
      simBuf=""; simShowDial(); return;
    }
    simOpen=true; simText=""; simBuf="";
    simSession="web-"+Math.random().toString(36).slice(2,9);
  }
  await simPost();
}
async function simPost(){
  const dot=$('simDot'), txt=$('simTxt');
  if(dot)dot.className='dot'; if(txt)txt.textContent='sending…';
  try{
    const res=await fetch('ussd.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({sessionId:simSession,serviceCode:'*384#',
        phoneNumber:simPhoneNum||'+255700000000',text:simText})
    });
    const raw=(await res.text()).trim();
    if(dot)dot.className='dot live'; if(txt)txt.textContent='live · ussd.php responded';
    if(raw.startsWith('CON ')){ simScreen("USSD · *384#",raw.slice(4)); }
    else if(raw.startsWith('END ')){
      simScreen("Session ended",raw.slice(4)+"\n\n— END —");
      simOpen=false; simText=""; setTimeout(load,600);
    } else { simScreen("Unexpected reply",raw.slice(0,180)||"(empty)"); simOpen=false; simText=""; }
    simShowDial();
  }catch(e){
    if(dot)dot.className='dot off'; if(txt)txt.textContent='endpoint unreachable';
    simScreen("Can't reach the server","ussd.php did not respond.\nDeploy it at this path and retry.");
    simOpen=false;
  }
}
function simReset(){
  if(simOpen && simSession){
    try{ navigator.sendBeacon && navigator.sendBeacon('ussd.php',
      new URLSearchParams({sessionId:simSession,phoneNumber:(simPhoneNum||'+255700000000'),text:'',abandon:'1'})); }
    catch(e){}
  }
  simOpen=false; simText=""; simBuf="";
  simSession="web-"+Math.random().toString(36).slice(2,9);
  simScreen("Idle","Pick a value chain and press Run process.");
  simShowDial();
}
function fullReset(){ runActive=false; runIdx=0; simReuseCaller=false; simReset(); runnerPick(); }

function redialSameHousehold(){
  const reuse=simPhoneNum||simLastPhoneNum;
  if(!reuse){ const a=$('rAct'); if(a)a.innerHTML="Run at least one household first, then a re-dial can update it."; return; }
  simPhoneNum=reuse; simReuseCaller=true; runActive=false; runIdx=0;
  setCallerLabel();
  const s=$('rStep'),a=$('rAct'),b=$('runBtn');
  if(s)s.textContent="Same household re-dials";
  if(a)a.innerHTML="Caller <b>"+maskNum(simPhoneNum)+"</b> already exists. Press <b>Run process</b> — this <b>updates</b> the record (e.g. a pregnancy now reported) instead of creating a new one.";
  if(b){b.disabled=false; b.textContent="Run process ▸";}
  renderFlow(null,null);
}

/* ================= GUIDED RUNNER (five value chains) ================= */
const CHAINS = {
  register:{ name:"1 · Register the demand", role:"household", msh:"Selection & Quantification",
    ctx:"Each household self-reports its size and — crucially — its sleeping places (mattresses), the real unit of net demand. This builds the live denominator the whole supply chain assumes.",
    flow:[{role:"household",what:"Household dials in"},{role:"household",what:"States household size"},
          {role:"household",what:"States sleeping places"},{role:"household",what:"Under-5s & pregnancy"},
          {role:"household",what:"Entitlement created"}],
    steps:[{key:"1",say:"Dial in and choose <b>Register</b> (1). The server opens the register chain."},
           {key:"6",say:"Household size: <b>6 people</b>."},
           {key:"4",say:"<b>4 sleeping places</b> — the net-demand unit, not headcount."},
           {key:"2",say:"<b>2 children under 5</b> (a priority group)."},
           {key:"1",say:"No pregnancy. The server <b>writes the household</b>, derives a capped entitlement, and ends the session.",last:true}] },
  quantify:{ name:"2 · Quantify & allocate", role:"allocator", msh:"Selection & Quantification",
    ctx:"Turn the register into capped net entitlements. The allocator confirms the entitlement reconciles against the household record before vouchers are issued.",
    flow:[{role:"allocator",what:"Open allocation"},{role:"allocator",what:"Reconcile vs register"}],
    steps:[{key:"2",say:"Choose <b>Quantify & allocate</b> (2)."},
           {key:"1",say:"Entitlement reconciles against the register — report <b>OK</b>. Saved.",last:true}] },
  supply:{ name:"3 · Supply to CHC", role:"store", msh:"Procurement",
    ctx:"Upstream logistics: funding, procurement, central store, transport, CHC store. The storekeeper confirms nets have arrived and are stored securely.",
    flow:[{role:"store",what:"Open supply report"},{role:"store",what:"Confirm received & stored"}],
    steps:[{key:"3",say:"Choose <b>Supply to CHC</b> (3)."},
           {key:"1",say:"Nets received and stored securely — report <b>OK</b>. Saved.",last:true}] },
  lastmile:{ name:"4 · Last-mile & resistance", role:"lastmile", msh:"Storage & Distribution",
    ctx:"The final stretch plus getting the resistance-critical CFP nets to the Belessa district. Confirms the right nets reached the resistant zone.",
    flow:[{role:"lastmile",what:"Open last-mile report"},{role:"lastmile",what:"CFP reached Belessa"}],
    steps:[{key:"4",say:"Choose <b>Last-mile & resistance</b> (4)."},
           {key:"1",say:"CFP nets reached Belessa — report <b>OK</b>. Saved.",last:true}] },
  protect:{ name:"5 · Protection & monitoring", role:"monitor", msh:"Use",
    ctx:"Delivered vs. protected. The monitor confirms nets are actually slept under — and this re-survey feeds back into Chain 1, closing the loop.",
    flow:[{role:"monitor",what:"Open monitoring"},{role:"monitor",what:"Confirm net used"}],
    steps:[{key:"5",say:"Choose <b>Protection & monitoring</b> (5)."},
           {key:"1",say:"Slept under net last night — report <b>OK</b>. Saved. In the full system this also refreshes the register.",last:true}] },
};
const ORDER=['register','quantify','supply','lastmile','protect'];
const SHORT={register:'1 Register',quantify:'2 Quantify',supply:'3 Supply',lastmile:'4 Last-mile',protect:'5 Protection'};
let runKey="register", runIdx=0, runActive=false;

function renderFlow(activeIdx, doneUpTo){
  const cyc=$('cycleStrip'); if(!cyc) return;
  cyc.innerHTML = ORDER.map(k=>{
    const on = k===runKey ? ' on' : '';
    return `<div class="cyclechip${on}" onclick="KABAMO.selectChain('${k}')" title="Select this value chain">`+
           `<span class="cn">${SHORT[k]}</span><span class="msh">${CHAINS[k].msh}</span></div>`;
  }).join('');
  const fr=$('flowRow'); if(!fr) return;
  const c=CHAINS[runKey]; let html='';
  c.flow.forEach((f,i)=>{
    let cls='fstep';
    if(typeof activeIdx==='number' && i===activeIdx) cls+=' active';
    else if(typeof doneUpTo==='number' && i<=doneUpTo) cls+=' done';
    const col=SIM_ROLES[f.role].color;
    const status=(typeof activeIdx==='number'&&i===activeIdx)?'running…'
               :(typeof doneUpTo==='number'&&i<=doneUpTo)?'✓ done':'';
    html+=`<div class="${cls}"><span class="fnum">${i+1}</span>`+
          `<span class="frole" style="background:${col}">${SIM_ROLES[f.role].name}</span>`+
          `<div class="fwhat">${f.what}</div><div class="fstatus">${status}</div></div>`;
    if(i<c.flow.length-1){
      const lit=(typeof doneUpTo==='number'&&i<=doneUpTo)?' lit':'';
      html+=`<div class="farrow${lit}">▸</div>`;
    }
  });
  fr.innerHTML=html;
}

function runnerPick(){
  const sel=$('chainSel'); if(!sel) return;
  runKey=sel.value; runIdx=0; runActive=false;
  const c=CHAINS[runKey];
  setHTML('chainCtx',c.ctx);
  setHTML('whoLine',"<b>"+SIM_ROLES[c.role].name+"</b> — acts in this chain · maps to MSH “"+c.msh+"”");
  setTxt('rStep',"Ready");
  setHTML('rAct',"Press <b>Run process</b> to walk this chain. Each step is a real USSD exchange.");
  const b=$('runBtn'); if(b){b.textContent="Run process ▸"; b.disabled=false;}
  simRoleKey=c.role; simChrome();
  renderFlow(null,null);
}
function selectChain(k){ const sel=$('chainSel'); if(!sel) return; sel.value=k; runnerPick(); }

async function runnerNext(){
  const c=CHAINS[runKey], btn=$('runBtn'); if(!btn) return;
  if(!runActive){
    runActive=true; runIdx=0;
    if(!simReuseCaller){ if(simPhoneNum) simLastPhoneNum=simPhoneNum; simPhoneNum=newTzNumber(); }
    simReuseCaller=false; setCallerLabel();
    simReset(); simBuf="*384#"; await simSend();
    setTxt('rStep',c.name+"  ·  session open");
    setHTML('rAct',"Dialled *384# — server returned the chain menu. Press <b>Next step</b>.");
    btn.textContent="Next step ▸"; renderFlow(0,null); return;
  }
  if(runIdx<c.steps.length){
    const st=c.steps[runIdx];
    btn.disabled=true; simPress(st.key);
    setTxt('rStep',c.name+"  ·  step "+(runIdx+1)+" of "+c.steps.length);
    setHTML('rAct',st.say);
    renderFlow(runIdx,runIdx-1); runIdx++;
    setTimeout(()=>{ btn.disabled=false; },700);
    if(st.last){
      btn.textContent="Done";
      setTimeout(()=>{
        setTxt('rStep',c.name+"  ·  complete");
        setHTML('rAct',"✅ Process complete — written to the live database. The register below has updated.");
        btn.disabled=true; renderFlow(null,c.flow.length-1); runActive=false;
      },800);
    }
  }
}

/* ================= DASHBOARD (+ KPI teaser) ================= */
async function load(){
  const onDash = $('regionGrid')||$('statCards')||$('k_hh');
  if(!onDash) return;
  try{
    const res=await fetch('dashboard.php',{cache:'no-store'});
    const d=await res.json();
    if(!d.ok) throw new Error(d.error||'no data');
    render(d);
    live('liveDot','liveTxt','live · updated '+new Date(d.generated_at).toLocaleString());
    live('kpiDot','kpiUpdated','live · '+new Date(d.generated_at).toLocaleTimeString());
  }catch(e){
    off('liveDot','liveTxt','no data yet — run the USSD flow, or start the server');
    off('kpiDot','kpiUpdated','no data yet');
  }
}
function live(dotId,txtId,msg){ const d=$(dotId),t=$(txtId); if(d)d.className='dot live'; if(t)t.textContent=msg; }
function off(dotId,txtId,msg){ const d=$(dotId),t=$(txtId); if(d)d.className='dot off'; if(t)t.textContent=msg; }
function setTxt(id,v){ const e=$(id); if(e) e.textContent=v; }
function setHTML(id,v){ const e=$(id); if(e) e.innerHTML=v; }

function render(d){
  const r=d.register||{};
  setTxt('s_hh',fmt(r.households));        setTxt('k_hh',fmt(r.households));
  setTxt('s_spaces',fmt(r.sleeping_spaces));setTxt('k_spaces',fmt(r.sleeping_spaces));
  setTxt('s_nets',fmt(r.nets_entitled));   setTxt('k_nets',fmt(r.nets_entitled));
  setTxt('s_npp',r.nets_per_person??'—');  setTxt('k_npp',r.nets_per_person??'—');
  setTxt('kanon',d.k_anon);

  // sleeping-space distribution
  const dist=d.spaces_distribution||[], maxN=Math.max(1,...dist.map(x=>x.count||0)), dc=$('distChart');
  if(dc){
    dc.innerHTML = !dist.length ? '<p class="note">No registrations yet.</p>'
      : dist.map(x=>{
          if(x.count==null) return `<div class="bar"><div class="lab">${x.spaces} spaces</div><div class="track"></div><div class="n suppressed">&lt;k</div></div>`;
          const w=Math.round((x.count/maxN)*100);
          return `<div class="bar"><div class="lab">${x.spaces} spaces</div><div class="track"><div class="fill" style="width:${w}%"></div></div><div class="n">${x.count}</div></div>`;
        }).join('');
  }

  // freshness {fresh, due}
  const f=d.freshness||{fresh:0,due:0}, tot=Math.max(1,(f.fresh||0)+(f.due||0)), fb=$('freshBar');
  if(fb) fb.innerHTML=`<div class="f" style="width:${Math.round((f.fresh||0)/tot*100)}%"></div><div class="d" style="width:${Math.round((f.due||0)/tot*100)}%"></div>`;
  setTxt('freshLabel',`${f.fresh||0} fresh · ${f.due||0} due for re-survey (>180 days)`);

  // chain readiness pills
  const chains=d.chains||{}, names={register:'Register the demand',quantify:'Quantify & allocate',supply:'Supply to CHC',lastmile:'Last-mile & resistance',protect:'Protection & monitoring'}, cl=$('chainList');
  if(cl){
    const present=ORDER.filter(k=>chains[k]);
    cl.innerHTML = !present.length ? '<p class="note">No reports yet.</p>'
      : present.map(k=>{ const c=chains[k], pill=(cls,v)=>`<span class="pill ${v>0?cls:'zero'}">${v}</span>`;
          return `<div class="chain"><span class="name">${names[k]||k}</span>${pill('ok',c.ok)}${pill('partial',c.partial)}${pill('problem',c.problem)}</div>`; }).join('');
  }

  // recent activity (d.activity.recent[])
  const ra=$('recentFeed');
  if(ra){
    const rec=(d.activity&&d.activity.recent)||[];
    ra.innerHTML = !rec.length ? '<p class="note">No registrations yet.</p>'
      : rec.map(x=>{ const ago=timeAgo(x.at), upd=x.round>1?`<span class="upd">updated · round ${x.round}</span>`:`<span class="new">new</span>`, sp=(x.spaces==null)?'':`${x.spaces} sleeping spaces · `;
          return `<div class="rafeed"><span class="rahh">${x.hh}</span><span class="rameta">${sp}${ago}</span>${upd}</div>`; }).join('');
  }

  // seed banner (d.seeded is a COUNT)
  const sb=$('seedBanner');
  if(sb){ sb.style.display=(d.seeded>0)?'block':'none';
    if(d.seeded>0) sb.textContent="Sample data: "+d.seeded+" synthetic households are pre-loaded for demonstration. They are clearly flagged and never mixed with real registrations."; }

  // region coverage scatter-dot map (d.regions[])
  const reg=d.regions||[], rg=$('regionGrid');
  if(rg){
    rg.innerHTML = !reg.length ? '<p class="note">No district data.</p>'
      : reg.map(x=>{
          const pct=Math.round((x.coverage||0)*100), dotN=Math.min(40,x.reported||0);
          let dots=''; for(let i=0;i<dotN;i++){ const px=10+Math.random()*115, py=12+Math.random()*76; dots+=`<circle cx="${px}" cy="${py}" r="2.2" class="hhdot"/>`; }
          const cls=x.status, needsNudge=x.status!=='ok';
          return `<div class="region ${cls}"><div class="rgtop"><span class="rgname">${x.name}</span><span class="rgpct ${cls}">${pct}%</span></div>`+
                 `<svg class="rgbox ${cls}" viewBox="0 0 135 100" preserveAspectRatio="xMidYMid meet"><rect x="1" y="1" width="133" height="98" rx="4" class="rgrect"/>${dots}</svg>`+
                 `<div class="rgmeta">${x.reported}/${x.expected} households · ${x.spaces} spaces</div>`+
                 (needsNudge?`<button class="nudge" onclick="KABAMO.sendReminder('${(x.name||'').replace(/'/g,'')}',${pct})">↗ Send SMS reminder</button>`:`<div class="rgok">✓ on track</div>`)+
                 `</div>`;
        }).join('');
  }
}
function sendReminder(name,pct){
  const box=$('reminderLog'); if(!box) return;
  const t=new Date().toLocaleTimeString();
  box.style.display='block';
  box.innerHTML=`<b>Reminder queued (simulated)</b> · ${t}<br>District <b>${name}</b> at ${pct}% coverage — an SMS prompt to dial *384# would be sent to households not yet registered for Chain 1. <span class="muted">No message is actually sent in this demo; live sending requires the aggregator, consent and a data custodian.</span>`+box.innerHTML;
}
function timeAgo(iso){
  if(!iso) return '';
  const t=new Date(iso.replace(' ','T')+'Z').getTime(), s=Math.max(0,Math.floor((Date.now()-t)/1000));
  if(s<60) return s+'s ago';
  if(s<3600) return Math.floor(s/60)+'m ago';
  if(s<86400) return Math.floor(s/3600)+'h ago';
  return Math.floor(s/86400)+'d ago';
}

/* ================= nav active state ================= */
function markNav(){
  const here=(location.pathname.split('/').pop()||'index.html');
  document.querySelectorAll('.topnav a').forEach(a=>{
    const href=a.getAttribute('href');
    if(href===here||(here===''&&href==='index.html')) a.classList.add('active');
  });
}

/* ================= boot ================= */
// expose the handful of functions referenced by inline onclick=""
window.KABAMO = { selectChain, sendReminder, runnerNext, fullReset, redialSameHousehold,
                  simPress, simBack, simSend };

document.addEventListener('DOMContentLoaded',function(){
  markNav();
  // wire the chain dropdown + initialise the coupled machine (demo page only)
  const sel=$('chainSel');
  if(sel){
    sel.innerHTML=ORDER.map(k=>`<option value="${k}">${CHAINS[k].name}</option>`).join('');
    sel.addEventListener('change',runnerPick);
    if($('simPhone')){ simChrome(); simReset(); setCallerLabel(); }
    runnerPick();
  }
  load();
  setInterval(load,15000);
});
})();

document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{const el=document.querySelector(b.dataset.copy);if(!el)return;try{await navigator.clipboard.writeText(el.textContent.trim());const t=b.textContent;b.textContent='Copied!';setTimeout(()=>b.textContent=t,1400)}catch{b.textContent='Copy unavailable'}}));document.querySelectorAll('[data-toggle]').forEach(b=>b.addEventListener('click',()=>{const input=document.querySelector(b.dataset.toggle);if(input)input.type=input.type==='password'?'text':'password'}));});
/* Merchant panel behaviour: sidebar drawer, copy-to-clipboard chips, confirm
   dialogs and counter animation. Vanilla JS, no build step. */
function openSidebar(){var s=document.getElementById('sidebar'),o=document.getElementById('sidebarOverlay'),b=document.getElementById('closeSidebarBtn');if(!s)return;s.classList.add('open');if(o)o.style.display='block';if(b)b.style.display='flex';}
function closeSidebar(){var s=document.getElementById('sidebar'),o=document.getElementById('sidebarOverlay'),b=document.getElementById('closeSidebarBtn');if(!s)return;s.classList.remove('open');if(o)o.style.display='none';if(b)b.style.display='none';}
function copyNavMid(mid,el){
  navigator.clipboard.writeText(mid).then(function(){var b=el&&el.querySelector('.sidebar-mid-copy-btn');if(!b)return;var t=b.innerHTML;b.innerHTML='<span style="color:#22c55e;font-size:10.5px;font-weight:700">Copied!</span>';setTimeout(function(){b.innerHTML=t;},1500);});
}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('[data-copy]').forEach(function(el){
    el.addEventListener('click',function(){
      var value=el.getAttribute('data-copy');
      if(!value)return;
      navigator.clipboard.writeText(value).then(function(){var t=el.getAttribute('data-copied-label')||'Copied!';el.classList.add('copied');el.setAttribute('data-original',el.getAttribute('title')||'');el.setAttribute('title',t);setTimeout(function(){el.classList.remove('copied');},1400);});
    });
  });
  /* Animate stat numbers up from zero. */
  document.querySelectorAll('.stat-card-value[data-count]').forEach(function(el){
    var target=parseFloat(el.getAttribute('data-count'))||0,prefix=el.getAttribute('data-prefix')||'',decimals=parseInt(el.getAttribute('data-decimals')||'0',10),start=null;
    function step(ts){if(!start)start=ts;var p=Math.min(1,(ts-start)/600);var v=target*p;el.textContent=prefix+v.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g,',');if(p<1)requestAnimationFrame(step);}
    requestAnimationFrame(step);
  });
});

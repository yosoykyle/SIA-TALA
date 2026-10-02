const app=document.getElementById('app'), view=document.getElementById('view'), search=document.getElementById('search'), collapse=document.getElementById('collapse'), dialog=document.getElementById('dialog'), mobile=matchMedia('(max-width:640px)');

const resizer=document.getElementById('sidebar-resize');

const searchToggle=document.getElementById('search-toggle');

const headerCollapse=document.getElementById('header-collapse');

function setSearchOpen(open){app.classList.toggle('search-open',open);searchToggle.setAttribute('aria-expanded',String(open));searchToggle.setAttribute('aria-label',open?'Close search':'Open search');searchToggle.innerHTML=icon(open?'x':'search');icons();if(open)search.focus();else{search.value='';render();searchToggle.focus();}}

let searchHoverTimer;

function cancelSearchHover(){clearTimeout(searchHoverTimer);searchHoverTimer=undefined;}

function openCompactSearch(){headerPinned=true;scrollCollapsed=false;prefs.collapsed=false;resizeSidebar(prefs.sidebarWidth);setHeaderCompact(true);applyPrefs();save();search.focus();}

searchToggle.addEventListener('click',()=>{cancelSearchHover();if(!mobile.matches)openCompactSearch();else setSearchOpen(!app.classList.contains('search-open'));});

searchToggle.addEventListener('pointerenter',event=>{cancelSearchHover();if(event.pointerType!=='mouse'||mobile.matches||!isSidebarCollapsed())return;searchHoverTimer=setTimeout(()=>{searchHoverTimer=undefined;if(!mobile.matches&&isSidebarCollapsed()&&searchToggle.matches(':hover'))openCompactSearch();},500);});

searchToggle.addEventListener('pointerleave',cancelSearchHover);

searchToggle.addEventListener('pointercancel',cancelSearchHover);

window.addEventListener('blur',cancelSearchHover);

mobile.addEventListener('change',cancelSearchHover);

const sidebar=document.getElementById('sidebar');

let sidebarHoverTimer,sidebarHoverExpanded=false;

function cancelSidebarHover(){clearTimeout(sidebarHoverTimer);sidebarHoverTimer=undefined;}

function closeSidebarHover(){if(sidebar.matches(':hover')||(sidebar.contains(document.activeElement)&&document.activeElement!==collapse)||document.activeElement===search)return;cancelSidebarHover();if(!sidebarHoverExpanded)return;sidebarHoverExpanded=false;prefs.collapsed=true;scrollCollapsed=false;applyPrefs();}

function scheduleSidebarHover(){cancelSidebarHover();if(mobile.matches||!isSidebarCollapsed()||collapse.matches(':hover'))return;sidebarHoverTimer=setTimeout(()=>{sidebarHoverTimer=undefined;if(mobile.matches||!sidebar.matches(':hover')||!isSidebarCollapsed()||collapse.matches(':hover'))return;sidebarHoverExpanded=true;headerPinned=true;scrollCollapsed=false;prefs.collapsed=false;setHeaderCompact(true);applyPrefs();},500);}

sidebar.addEventListener('mouseenter',scheduleSidebarHover);

collapse.addEventListener('mouseenter',cancelSidebarHover);

collapse.addEventListener('mouseleave',()=>{if(sidebar.matches(':hover'))scheduleSidebarHover();});

collapse.addEventListener('focus',cancelSidebarHover);

sidebar.addEventListener('mouseleave',closeSidebarHover);

sidebar.addEventListener('pointercancel',closeSidebarHover);

document.addEventListener('focusout',()=>queueMicrotask(closeSidebarHover));

mobile.addEventListener('change',()=>{cancelSidebarHover();if(sidebarHoverExpanded){sidebarHoverExpanded=false;prefs.collapsed=true;applyPrefs();}});

search.addEventListener('blur',()=>{if(mobile.matches||!headerCompact)return;headerPinned=true;scrollCollapsed=false;prefs.collapsed=true;applyPrefs();save();});

search.addEventListener('keydown',event=>{if(event.key==='Escape'&&app.classList.contains('search-open')){event.preventDefault();setSearchOpen(false);}});

let saved;try{saved=JSON.parse(localStorage.getItem('workspace-layout-v2'));}catch{}

let prefs={sidebarWidth:Number.isFinite(saved?.sidebarWidth)?Math.min(360,Math.max(200,saved.sidebarWidth)):230,collapsed:Boolean(saved?.collapsed),light:Boolean(saved?.light),compact:Boolean(saved?.compact),name:typeof saved?.name==='string'?saved.name:'Jamie Davis'}, route='overview', toastTimer;

function resizeLimit(){return Math.max(200,Math.min(360,app.clientWidth-parseFloat(getComputedStyle(app).paddingLeft)-parseFloat(getComputedStyle(app).paddingRight)-340));}

function resizeSidebar(width){prefs.sidebarWidth=Math.round(Math.max(200,Math.min(resizeLimit(),width)));app.style.setProperty('--sidebar-width',prefs.sidebarWidth+'px');resizer.setAttribute('aria-valuenow',prefs.collapsed?62:prefs.sidebarWidth);resizer.setAttribute('aria-valuemax',resizeLimit());}

let resizing=false,startX=0,startWidth=0,headerCompact=false,headerPinned=false,scrollCollapsed=false;

function isSidebarCollapsed(){return prefs.collapsed||scrollCollapsed;}

function setHeaderCompact(compact){compact=compact&&!mobile.matches;if(compact===headerCompact)return;headerCompact=compact;search.placeholder=compact?'Find…':'Find components…';app.classList.toggle('header-collapsed',compact);headerCollapse.innerHTML='<div class="crest-frame"><img class="brand-crest" src="assets/servitech-crest.webp" alt="Servitech Institute Asia"></div><span>TALA UI.</span>';icons();}

document.getElementById('content').addEventListener('scroll',event=>{if(resizing||mobile.matches)return;const top=event.currentTarget.scrollTop;if(top<8){headerPinned=false;scrollCollapsed=false;setHeaderCompact(prefs.collapsed);applyPrefs();}else if(top>24&&!headerPinned){scrollCollapsed=true;setHeaderCompact(true);applyPrefs();}},{passive:true});

resizer.addEventListener('pointerdown',event=>{if(event.button!==0||mobile.matches)return;event.preventDefault();startX=event.clientX;startWidth=document.getElementById('sidebar').getBoundingClientRect().width;resizing=true;app.classList.add('resizing');resizer.setPointerCapture(event.pointerId);});

resizer.addEventListener('pointermove',event=>{if(!resizing)return;const width=startWidth+event.clientX-startX;if(width<=150){prefs.collapsed=true;applyPrefs();}else if(width>=170){prefs.collapsed=false;scrollCollapsed=false;resizeSidebar(width);applyPrefs();}});

function stopResize(){if(!resizing)return;resizing=false;app.classList.remove('resizing');save();}

resizer.addEventListener('pointerup',stopResize);resizer.addEventListener('pointercancel',stopResize);resizer.addEventListener('lostpointercapture',stopResize);

resizer.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();if(event.key==='Home'||(event.key==='ArrowLeft'&&prefs.sidebarWidth<=200)){prefs.collapsed=true;}else{const width=event.key==='End'?resizeLimit():isSidebarCollapsed()?200:prefs.sidebarWidth+(event.key==='ArrowRight'?10:-10);prefs.collapsed=false;scrollCollapsed=false;resizeSidebar(width);}applyPrefs();save();});

resizer.addEventListener('dblclick',()=>{prefs.collapsed=false;resizeSidebar(230);applyPrefs();save();});

window.addEventListener('resize',()=>{if(!mobile.matches)resizeSidebar(prefs.sidebarWidth);});

function save(){try{localStorage.setItem('workspace-layout-v2',JSON.stringify({...saved,...prefs}));}catch{document.getElementById('storage-status').textContent='Changes kept for this session';}}

function icon(name){return `<i data-lucide="${name}" aria-hidden="true"></i>`;}

function icons(){lucide.createIcons({attrs:{'aria-hidden':'true','focusable':'false'}});}

function esc(value){return String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

function setAppearance(light){const root=document.documentElement;if(root.classList.contains('light')===light)return;const guard=document.createElement('style');guard.textContent='*,*::before,*::after{transition:none!important}';document.head.append(guard);root.classList.toggle('light',light);void root.offsetHeight;requestAnimationFrame(()=>requestAnimationFrame(()=>guard.remove()));}
function applyPrefs(){if(!mobile.matches)resizeSidebar(prefs.sidebarWidth);const collapsed=isSidebarCollapsed();if(!mobile.matches&&collapsed)setHeaderCompact(true);app.classList.toggle('collapsed',collapsed);resizer.setAttribute('aria-valuemin','62');resizer.setAttribute('aria-valuenow',collapsed?62:prefs.sidebarWidth);setAppearance(prefs.light);app.classList.toggle('compact',prefs.compact);const expanded=mobile.matches?app.classList.contains('mobile-open'):!collapsed;collapse.setAttribute('aria-expanded',String(expanded));collapse.setAttribute('aria-label',expanded?'Collapse sidebar':'Expand sidebar');collapse.title=expanded?'Collapse sidebar':'Expand sidebar';document.getElementById('profile-name').textContent=prefs.name;const initials=prefs.name.trim().split(/\s+/).map(p=>p[0]).slice(0,2).join('').toUpperCase()||'JD';document.querySelectorAll('.topbar .avatar,.sidebar .avatar').forEach(el=>el.textContent=initials);}

function notify(message){const el=document.getElementById('toast');el.innerHTML='<span>'+esc(message)+'</span><button class="toast-close" type="button" aria-label="Dismiss notification">&times;</button>';el.hidden=false;clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.hidden=true,3200);}

collapse.addEventListener('click',()=>{cancelSidebarHover();sidebarHoverExpanded=false;if(mobile.matches)app.classList.toggle('mobile-open');else{prefs.collapsed=!isSidebarCollapsed();scrollCollapsed=false;headerPinned=true;setHeaderCompact(prefs.collapsed);}applyPrefs();save();});mobile.addEventListener('change',()=>{app.classList.remove('mobile-open');headerPinned=false;scrollCollapsed=false;setHeaderCompact(false);applyPrefs();});

document.addEventListener('click',event=>{if(event.target.closest('[data-close]'))dialog.close();});

document.addEventListener('keydown',event=>{if(event.key==='Escape'){app.classList.remove('mobile-open');applyPrefs();}if(event.key==='/'&&!event.ctrlKey&&!event.metaKey&&!event.altKey&&!event.target.closest('input,textarea')&&!dialog.open){event.preventDefault();if(matchMedia('(max-width:430px)').matches)setSearchOpen(true);else{if(headerCompact){headerPinned=true;setHeaderCompact(false);}search.focus();}}});

search.addEventListener('input',()=>filterSpecimens());

view.addEventListener('submit',event=>{if(event.target.id!=='profile-form')return;event.preventDefault();const name=document.getElementById('display-name');if(!name.value.trim()){name.setCustomValidity('Enter a display name.');name.reportValidity();return;}prefs.name=name.value.trim();save();render();notify('Profile updated');});view.addEventListener('input',event=>{if(event.target.id==='display-name')event.target.setCustomValidity('');});view.addEventListener('change',event=>{if(event.target.id==='light-toggle')prefs.light=event.target.checked;else if(event.target.id==='compact-toggle')prefs.compact=event.target.checked;else return;applyPrefs();save();});window.addEventListener('hashchange',()=>{cancelSidebarHover();cancelSearchHover();if(sidebarHoverExpanded){prefs.collapsed=true;sidebarHoverExpanded=false;}headerPinned=false;scrollCollapsed=false;setHeaderCompact(prefs.collapsed);render();document.getElementById('content').scrollTop=0;});























let mobileScrollAnchor=0;

function showMobileNavigation(){app.classList.remove('mobile-nav-hidden');mobileScrollAnchor=document.getElementById('content').scrollTop;}

document.getElementById('content').addEventListener('scroll',event=>{

 if(!mobile.matches)return;

 const top=Math.max(0,event.currentTarget.scrollTop),delta=top-mobileScrollAnchor;

 if(top<=8){showMobileNavigation();return;}

 if(Math.abs(delta)<8)return;

 const navigationFocused=document.activeElement.closest('.topbar,.phone-nav');

 app.classList.toggle('mobile-nav-hidden',delta>0&&top>24&&!navigationFocused);

 mobileScrollAnchor=top;

},{passive:true});

window.addEventListener('hashchange',showMobileNavigation);

mobile.addEventListener('change',showMobileNavigation);

document.addEventListener('focusin',event=>{if(mobile.matches&&event.target.closest('.topbar,.phone-nav'))showMobileNavigation();});



document.getElementById('toast').addEventListener('click',event=>{if(event.target.closest('.toast-close')){clearTimeout(toastTimer);document.getElementById('toast').hidden=true;}});


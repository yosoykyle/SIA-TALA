const themeButton=document.getElementById('theme-toggle');
let theme='light';
try{theme=localStorage.getItem('tala-native-public-theme')|| (matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');}catch{}
function applyTheme(){document.documentElement.dataset.bsTheme=theme;themeButton.textContent=theme==='dark'?'Use light theme':'Use dark theme';}
applyTheme();
themeButton.addEventListener('click',()=>{theme=theme==='dark'?'light':'dark';applyTheme();try{localStorage.setItem('tala-native-public-theme',theme);}catch{}});
document.getElementById('public-example').addEventListener('submit',event=>{event.preventDefault();document.getElementById('public-result').hidden=false;});

/* A same-origin POST completes the OIDC callback without relaxing the Strict session cookie. */
(async()=>{
 'use strict';
 const params=new URLSearchParams(location.search),state=params.get('state'),code=params.get('duo_code');
 history.replaceState(null,'',location.pathname);
 const status=document.querySelector('#duo-result');
 try{
  if(!state||!code||state.length>1024||code.length>4096)throw new Error('Brak potwierdzenia Duo. Rozpocznij logowanie lub konfigurację ponownie.');
  const sessionResponse=await fetch('api.php?action=session',{credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',signal:AbortSignal.timeout(15000)});
  if(!sessionResponse.ok)throw new Error('Nie można odczytać sesji. Wróć do STOCK i spróbuj ponownie.');
  const session=await sessionResponse.json();
  const response=await fetch('api.php?action=duo-finish',{method:'POST',credentials:'same-origin',cache:'no-store',referrerPolicy:'no-referrer',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf},body:JSON.stringify({state,duo_code:code}),signal:AbortSignal.timeout(25000)});
  const result=await response.json();if(!response.ok)throw new Error(result.error||'Duo nie potwierdziło logowania.');
  status.textContent=result.duoActivated?'Duo Push zostało włączone. Otwieramy ustawienia…':'Logowanie potwierdzone. Otwieramy portfel…';
  location.replace('./#'+(result.returnRoute==='settings'?'settings':'dashboard'));
 }catch(e){status.textContent=e.message||'Nie udało się potwierdzić Duo. Wróć do STOCK i spróbuj ponownie.';}
})();

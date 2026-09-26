(()=>{'use strict';const c=window.IntelinkBookingActivity;if(!c||!Array.isArray(c.items)||!c.items.length)return;
const box=document.createElement('aside');box.className='ib-activity-toast ib-activity-'+c.position;box.setAttribute('role','status');box.setAttribute('aria-live','polite');box.hidden=true;
const close=document.createElement('button');close.type='button';close.className='ib-activity-close';close.setAttribute('aria-label','Dismiss recent booking notification');close.textContent='×';
const media=document.createElement('span');media.className='ib-activity-media';const img=document.createElement('img');img.alt='';img.loading='lazy';media.append(img);
const content=document.createElement('div');content.className='ib-activity-copy';const label=document.createElement('small');label.textContent='Recently booked';const title=document.createElement('strong');const ago=document.createElement('span');content.append(label,title,ago);box.append(media,content,close);document.body.append(box);
let index=0,hideTimer,showTimer;const hide=()=>{box.hidden=true;clearTimeout(hideTimer)};close.addEventListener('click',hide);
const show=()=>{const item=c.items[index++%c.items.length];title.textContent=item.service+' was booked';ago.textContent=item.ago;img.src=item.image||'';media.hidden=!c.showImage||!item.image;box.hidden=false;clearTimeout(hideTimer);hideTimer=setTimeout(hide,c.duration*1000)};
showTimer=setTimeout(()=>{show();showTimer=setInterval(show,c.interval*1000)},c.delay*1000);
document.addEventListener('visibilitychange',()=>{if(document.hidden){hide();clearInterval(showTimer)}});
if(c.url){title.style.cursor='pointer';title.tabIndex=0;title.setAttribute('role','link');title.addEventListener('click',()=>location.assign(c.url));title.addEventListener('keydown',e=>{if(e.key==='Enter')location.assign(c.url)})}
})();

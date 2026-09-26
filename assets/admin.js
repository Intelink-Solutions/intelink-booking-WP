document.addEventListener('click', event => {
  const confirmButton = event.target.closest('.ib-confirm');
  if (confirmButton && !window.confirm(confirmButton.dataset.confirm)) event.preventDefault();
  const button = event.target.closest('.ib-media');
  if (!button || !window.wp?.media) return;
  const frame = wp.media({title:'Choose image',button:{text:'Use image'},library:{type:'image'},multiple:false});
  frame.on('select', () => { document.getElementById(button.dataset.target).value = frame.state().get('selection').first().toJSON().id; });
  frame.open();
});
// Keep the featured-image preview in sync with the WordPress Media Library.
document.addEventListener('click', event => {
  const button=event.target.closest('.ib-service-editor .ib-media');
  if(!button || !window.wp?.media)return;
  // This is registered after the original handler, so update the preview when its field changes.
  const target=document.getElementById(button.dataset.target), preview=document.getElementById('ib-image-preview');
  if(!target||!preview)return;
  let previous=target.value;
  const observer=new MutationObserver(()=>{}); observer.disconnect();
  const poll=setInterval(()=>{if(target.value!==previous){previous=target.value;const id=Number(target.value);if(id && wp.media.attachment(id)){const attachment=wp.media.attachment(id);attachment.fetch().then(()=>{const url=attachment.get('url');if(url)preview.innerHTML='<img alt="Service image" src="'+url.replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'">';});}clearInterval(poll);}},250);
  setTimeout(()=>clearInterval(poll),20000);
});

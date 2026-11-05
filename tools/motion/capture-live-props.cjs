// Accessoires de la vidéo du live : photos des produits et conversation WhatsApp (tools/motion/props/live)
//   node tools/motion/capture-live-props.cjs
const { chromium } = require('playwright');
(async () => {
  const dir = require('node:path').join(__dirname, 'props/live');
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 600, height: 600 } });
  await p.goto('file://' + dir + '/produits.html');
  for (const id of ['robe', 'sac', 'chaussures', 'montre']) await p.locator('#' + id).screenshot({ path: `${dir}/${id}.jpg`, quality: 85, type: 'jpeg' });
  const w = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3 });
  for (const n of [1, 2, 4]) { await w.goto(`file://${dir}/whatsapp.html?n=${n}`); await w.waitForTimeout(300); await w.screenshot({ path: require('node:path').join(__dirname, 'assets', `live-whatsapp-${n}.png`) }); }
  await b.close();
})();

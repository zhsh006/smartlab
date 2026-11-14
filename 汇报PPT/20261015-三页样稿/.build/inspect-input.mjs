import fs from 'node:fs/promises';
import {PresentationFile,FileBlob} from '@oai/artifact-tool';
const p=await PresentationFile.importPptx(await FileBlob.load('C:/Users/zhsh0/Downloads/SmartLab_校内项目.pptx'));
for(const n of [2,4,5]){
 const b=await p.export({slide:p.slides.items[n],format:'png',scale:1});
 await fs.writeFile(`input-${n+1}.png`,new Uint8Array(await b.arrayBuffer()));
}
console.log('Rendered source slides 3, 5, 6');

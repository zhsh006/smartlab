import path from 'node:path';
import {pathToFileURL} from 'node:url';
const skill='C:/Users/zhsh0/.codex/plugins/cache/openai-primary-runtime/presentations/26.1013.11914/skills/presentations';
const root=path.resolve('..');
process.env.RUNTIME_NODE_MODULES='C:/Users/zhsh0/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules';
const {finalizePresentation}=await import(pathToFileURL(path.join(skill,'container_tools/artifact_tool_utils.mjs')).href);
const r=await finalizePresentation({
 explicitTotalSlideCount:3,
 workspaceDir:root,
 candidatePath:path.join(root,'.codex-finalizer/candidate.pptx'),
 finalPath:path.join(root,'output/SmartLab_讲座宣传_三页样稿.pptx'),
 pythonExecutable:'C:/Users/zhsh0/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe',
 integrityValidatorPath:path.join(skill,'container_tools/inspect_presentation_package_integrity.py'),
 layoutValidatorPath:path.join(skill,'container_tools/inspect_presentation_layout_geometry.py'),
 layoutArgs:['--expected-slide-size-emu','12192000,6858000','--validate-bullet-geometry','--validate-heading-fit'],
 requiredNativeTableOwnerSlides:[],
 requiredNativeChartOwnerSlides:[],
 fontPolicy:{basis:'design',families:['Microsoft YaHei','SimSun'],scriptFonts:{ea:'Microsoft YaHei'}},
 verifyArtifactToolImport:true,
 receiptPath:path.join(root,'.codex-finalizer/SmartLab.validation.json')
});
console.log(JSON.stringify(r,null,2));

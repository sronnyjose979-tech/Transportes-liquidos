import {createApp, h} from 'vue';
import SessionBoundary from '../System/SessionBoundary.vue';
import OfficeApp from './OfficeApp.vue';
import {createOfficeWorkspace} from './workspace';
import {decodeOfficeContext, officePaths} from './context';
import {pageLoaders} from './pages';
import {rememberSession} from '../System/sessionIdentity';

export function mountOffice(root: HTMLElement): void {
    const context=decodeOfficeContext(JSON.parse(document.getElementById('office-context')?.textContent??'null'));
    if(officePaths[context.page]!==location.pathname)throw new Error('Destino inválido.');
    rememberSession(context.session);
    const workspace=createOfficeWorkspace(context,pageLoaders);
    window.addEventListener('beforeunload',workspace.beforeUnload);
    createApp({render:()=>h(SessionBoundary,null,{default:()=>h(OfficeApp,{workspace})})}).use(workspace.router).mount(root);
}

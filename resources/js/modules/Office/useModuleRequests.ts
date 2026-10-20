import { onBeforeUnmount } from 'vue';
import * as api from '../Administration/api';

/** Cancel abandoned reads; mutations must retain their definitive or uncertain result. */
export function useModuleRequests() {
    const controller = new AbortController();
    onBeforeUnmount(() => controller.abort());
    const request: typeof api.request = (path, method = 'GET', body, signal) =>
        api.request(path, method, body, method === 'GET' ? signal ?? controller.signal : signal);
    const all: typeof api.all = (path, signal) => api.all(path, signal ?? controller.signal);
    const paginated: typeof api.paginated = (path, signal) => api.paginated(path, signal ?? controller.signal);
    return { request, all, paginated };
}

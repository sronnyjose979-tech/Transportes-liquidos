import { inject } from 'vue';
import { request } from '../Administration/api';
import { createNavigationLoader, navigationKey } from './navigation';
import type { Navigation, NavigationLoader } from './navigation';

export function useNavigation(): NavigationLoader {
    return inject(navigationKey, null) ?? createNavigationLoader(() => request<Navigation>('/navigation'));
}

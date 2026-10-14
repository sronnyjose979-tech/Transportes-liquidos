/** In-memory recovery only. Never stores documents or data in persistent browser storage. */
export interface PurchasingIntent { actor:string; path:string; body:Record<string,unknown>; title:string }
const pending=new Map<string,PurchasingIntent>();
export function pendingPurchase(actor:string):PurchasingIntent|null { return pending.get(actor) ?? null; }
export function rememberPurchase(intent:PurchasingIntent):void { pending.set(intent.actor,intent); }
export function clearPurchase(actor:string):void { pending.delete(actor); }

// Only unconfirmed upload intents are stored. No session credentials or read cache.
// A separate actor key prevents an account change from exposing another user's files.
export interface PendingUpload {
    actorId: string;
    requestKey: string;
    assetId: string;
    renewedId: string | undefined;
    typeId: string;
    issuedOn: string;
    expiresOn: string;
    file: File;
}
export const uploadsChanged = 'fleet-document-uploads-changed';
function database(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('transport-document-upload-recovery', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('pending', { keyPath: ['actorId', 'requestKey'] });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(new Error('No se pudo abrir la recuperación documental.'));
        request.onblocked = () => reject(new Error('Cierra otras pestañas para habilitar la recuperación documental.'));
    });
}
async function transaction<T>(mode: IDBTransactionMode, operation: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await database();
    try {
        return await new Promise<T>((resolve, reject) => {
            const tx = db.transaction('pending', mode, { durability: 'strict' });
            const request = operation(tx.objectStore('pending'));
            tx.oncomplete = () => resolve(request.result);
            tx.onabort = tx.onerror = () => reject(new Error('No se pudo conservar la carga pendiente. No se enviará un archivo sin recuperación local.'));
        });
    } finally { db.close(); }
}
export async function pendingUploads(actorId: string): Promise<PendingUpload[]> {
    return transaction('readonly', (store) => store.getAll(IDBKeyRange.bound([actorId, ''], [actorId, '\uffff']))) as Promise<PendingUpload[]>;
}
export async function preserveUpload(upload: PendingUpload): Promise<void> {
    await transaction('readwrite', (store) => store.add(upload));
    window.dispatchEvent(new Event(uploadsChanged));
}
export async function confirmUpload(upload: PendingUpload): Promise<void> {
    await transaction('readwrite', (store) => store.delete([upload.actorId, upload.requestKey]));
    window.dispatchEvent(new CustomEvent(uploadsChanged, { detail: upload.requestKey }));
}
export function uploadBody(upload: PendingUpload): FormData {
    const body = new FormData();
    body.append('request_key', upload.requestKey);
    if (!upload.renewedId) body.append('document_type_id', upload.typeId);
    body.append('file', upload.file, upload.file.name);
    if (upload.issuedOn) body.append('issued_on', upload.issuedOn);
    if (upload.expiresOn) body.append('expires_on', upload.expiresOn);
    return body;
}

export async function uploadFingerprint(upload: PendingUpload): Promise<string> {
    const hex = (bytes: ArrayBuffer): string => Array.from(new Uint8Array(bytes), (value) => value.toString(16).padStart(2, '0')).join('');
    const extension = upload.file.name.split('.').at(-1)?.toLowerCase() ?? '';
    const mime = ({ pdf: 'application/pdf', jpg: 'image/jpeg', png: 'image/png' } as Record<string, string>)[extension] ?? '';
    const fileHash = hex(await crypto.subtle.digest('SHA-256', await upload.file.arrayBuffer()));
    const canonical = JSON.stringify([upload.assetId.toLowerCase(), upload.renewedId?.toLowerCase() ?? null,
        upload.typeId.toLowerCase(), upload.issuedOn || null, upload.expiresOn || null,
        fileHash, upload.file.size, mime, extension]);
    return hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical)));
}

import { sessionFetch as fetch } from '../System/sessionIdentity';
import {ApiError,failure} from '../Administration/api';
import {refreshCsrf} from '../FleetAndDocuments/api';
/** Same session/CSRF policy as existing APIs; retain original File in memory for explicit recovery. */
export async function upload(path:string,body:Record<string,unknown>,file:File|null):Promise<void>{
 const token=()=>decodeURIComponent(document.cookie.split('; ').find(c=>c.startsWith('XSRF-TOKEN='))?.slice(11)??'');
 if(!token())await refreshCsrf();
 const form=new FormData();for(const [key,value] of Object.entries(body))if(value!==null&&value!==undefined)form.append(key,String(value));if(file)form.append('file',file,file.name);
 let response:Response;try{response=await fetch(`/api${path}`,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-XSRF-TOKEN':token()},body:form});}catch(e){throw failure(e);}
 const value:unknown=await response.json().catch(()=>null);
 if(!response.ok){const fields:Record<string,string[]>={};if(value&&typeof value==='object'&&'errors'in value&&value.errors&&typeof value.errors==='object')for(const[key,messages]of Object.entries(value.errors))if(Array.isArray(messages)&&messages.every(m=>typeof m==='string'))fields[key]=messages as string[];throw new ApiError(response.status,response.status===422?'Revisa los datos indicados.':response.status===403?'No tienes permiso para esta operación.':response.status===401?'Tu sesión terminó.':response.status===409?'El registro cambió. Consulta su historial.':'No se pudo confirmar la operación. Consulta su estado antes de reenviar.',fields);}
}

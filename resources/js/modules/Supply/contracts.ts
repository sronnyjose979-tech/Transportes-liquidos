export interface Part {id:string;code:string;description:string;unit:string}
export interface Supplier {id:string;name:string}
export interface PurchaseItem {id:string;spare_part_id:string;code:string;description:string;unit:string;quantity:string;workshop_material_need_id:string|null}
export interface PurchaseRequest {id:string;supplier:Supplier;reason:string;items:PurchaseItem[];decision:{decision:'approved'|'rejected';reason:string}|null}
export interface OrderItem extends PurchaseItem {purchase_request_item_id:string;authorized_quantity:string;received_quantity:string;pending_quantity:string}
export interface PurchaseOrder {id:string;purchase_request_id:string;supplier:Supplier;reason:string;items:OrderItem[];receipts:{id:string;received_at:string;reason:string;items:{id:string;purchase_order_item_id:string;quantity:string}[]}[]}
export interface Intent {actor:string;roles:string[];path:string;body:Record<string,unknown>;title:string}

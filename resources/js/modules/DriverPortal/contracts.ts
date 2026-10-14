export interface DriverAsset {id:string;fleet_asset_id:string;type:string;code:string;starts_at:string}
export interface DriverReport {id:string;type:string;information:Record<string,unknown>;occurred_at:string;registered_at:string}
export interface DriverReading {id:string;reading_type:string;reading_value:string;tractor_assignment_id:string;reason:string;occurred_at:string;registered_at:string}
export interface DriverTrip {id:string;product:string;execution_status:string;administrative_status:string;scheduled_start_at:string|null;route:{name:string;origin:string;destination:string};assets:DriverAsset[];my_reports?:DriverReport[];my_odometer?:DriverReading[]}
export interface DriverExpense {id:string;concept:string;category:string;amount:string;currency:string;status:string;occurred_at:string;receipts:{id:string}[]}

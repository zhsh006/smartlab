import type { InvenTreeStockItem } from "@/server/connectors/inventree/types";
import { inventreeRequest } from "@/server/connectors/inventree/client";

export async function listInventoryLots() {
  return inventreeRequest("/stock/") as Promise<InvenTreeStockItem[]>;
}

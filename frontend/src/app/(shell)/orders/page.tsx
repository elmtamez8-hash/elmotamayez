"use client";

import { useEffect, useState } from "react";
import { api, errorMessage } from "@/lib/api";
import type { Order } from "@/lib/types";

export default function OrdersPage() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [action, setAction] = useState<string | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api.get<{ data: Order[] }>("/orders")
      .then((res) => setOrders(res.data ?? []))
      .finally(() => setLoading(false));
  }, []);

  const handleApprove = async (uuid: string) => {
    setAction(uuid);
    try {
      await api.post(`/orders/${uuid}/approve`);
      setOrders(orders.map((o) => (o.uuid === uuid ? { ...o, status: "approved" } : o)));
    } catch {
      // ignore
    } finally {
      setAction(null);
    }
  };

  const handleReject = async (uuid: string) => {
    const reason = prompt("Reason for rejection (optional):") ?? "";
    setAction(uuid);
    try {
      await api.post(`/orders/${uuid}/reject`, { rejection_reason: reason || undefined });
      setOrders(orders.map((o) => (o.uuid === uuid ? { ...o, status: "rejected" } : o)));
    } catch {
      // ignore
    } finally {
      setAction(null);
    }
  };

  const handleReceipt = async (uuid: string, file: File) => {
    setAction(uuid);
    setError("");
    try {
      const form = new FormData();
      form.append("receipt", file);
      const updated = await api.upload<Order>(`/orders/${uuid}/receipt`, form);
      setOrders(orders.map((o) => (o.uuid === uuid ? updated : o)));
    } catch (err: unknown) {
      setError(errorMessage(err, "Could not upload the receipt"));
    } finally {
      setAction(null);
    }
  };

  const canManage = orders.some((o) => o.status === "pending" || o.status === "under_review");

  if (loading) return <div className="text-gray-400">Loading...</div>;

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Orders</h2>

      {error && <div className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</div>}

      {orders.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-gray-200">
          <p className="text-gray-500">No orders yet.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 bg-gray-50">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Course</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Amount</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Receipt</th>
                <th className="px-4 py-3 text-left font-medium text-gray-600">Date</th>
                {canManage && <th className="px-4 py-3 text-left font-medium text-gray-600">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {orders.map((order) => (
                <tr key={order.uuid} className="hover:bg-gray-50">
                  <td className="px-4 py-3">{order.course_title ?? "—"}</td>
                  <td className="px-4 py-3">{order.currency} {order.amount}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${
                      order.status === "approved" ? "bg-green-100 text-green-700" :
                      order.status === "pending" ? "bg-amber-100 text-amber-700" :
                      order.status === "rejected" ? "bg-red-100 text-red-700" :
                      "bg-gray-100 text-gray-600"
                    }`}>
                      {order.status.replace("_", " ")}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    {order.receipt_url ? (
                      <a
                        href={order.receipt_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs font-medium text-indigo-600 hover:underline"
                      >
                        View receipt
                      </a>
                    ) : order.is_mine && (order.status === "pending" || order.status === "under_review") ? (
                      <label className="cursor-pointer text-xs font-medium text-indigo-600 hover:underline">
                        {action === order.uuid ? "Uploading..." : "Upload receipt"}
                        <input
                          type="file"
                          accept=".jpg,.jpeg,.png,.pdf"
                          className="hidden"
                          disabled={action === order.uuid}
                          onChange={(e) => {
                            const file = e.target.files?.[0];
                            e.target.value = "";
                            if (file) handleReceipt(order.uuid, file);
                          }}
                        />
                      </label>
                    ) : (
                      <span className="text-xs text-gray-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-500">{new Date(order.created_at).toLocaleDateString()}</td>
                  {canManage && (
                    <td className="px-4 py-3">
                      {(order.status === "pending" || order.status === "under_review") ? (
                        <div className="flex gap-2">
                          <button
                            onClick={() => handleApprove(order.uuid)}
                            disabled={action === order.uuid}
                            className="rounded-lg bg-green-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-green-700 disabled:opacity-50"
                          >
                            Approve
                          </button>
                          <button
                            onClick={() => handleReject(order.uuid)}
                            disabled={action === order.uuid}
                            className="rounded-lg bg-red-100 px-3 py-1 text-xs font-medium text-red-700 transition hover:bg-red-200 disabled:opacity-50"
                          >
                            Reject
                          </button>
                        </div>
                      ) : (
                        <span className="text-xs text-gray-400">—</span>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

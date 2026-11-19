"""Small client for a self-hosted TG Gateways deployment."""
from __future__ import annotations
from typing import Any, Optional
import requests

class TGGatewayError(RuntimeError):
    """An HTTP or API error from TG Gateways."""

class TGGateway:
    def __init__(self, base_url: str, api_key: str, timeout: float = 10.0):
        if not base_url.startswith("https://"):
            raise ValueError("base_url must use HTTPS")
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update({"X-Api-Key": api_key, "Accept": "application/json"})

    def _request(self, method: str, path: str, **kwargs: Any) -> Any:
        response = self.session.request(method, self.base_url + path, timeout=self.timeout, **kwargs)
        try:
            data = response.json()
        except ValueError:
            data = None
        if not response.ok:
            message = data.get("error", response.reason) if isinstance(data, dict) else response.reason
            raise TGGatewayError(f"HTTP {response.status_code}: {message}")
        return data

    def create_order(self, amount: float, *, redirect_url: Optional[str] = None,
                     webhook_url: Optional[str] = None, customer_name: Optional[str] = None,
                     customer_email: Optional[str] = None, customer_phone: Optional[str] = None) -> dict:
        payload = {"amount": amount}
        for key, value in (("redirect_url", redirect_url), ("webhook_url", webhook_url),
                           ("customer_name", customer_name), ("customer_email", customer_email),
                           ("customer_phone", customer_phone)):
            if value is not None:
                payload[key] = value
        return self._request("POST", "/api/create-order.php", json=payload)

    def verify_order(self, order_id: str) -> dict:
        return self._request("GET", "/api/verify-order.php", params={"order_id": order_id})

    def checkout_status(self, order_id: str) -> dict:
        return self._request("GET", "/api/checkout-status.php", params={"order_id": order_id})

    def transactions(self, **filters: Any) -> dict:
        return self._request("GET", "/api/get-transactions.php", params=filters)

    def create_payment_link(self, title: str, amount: float, **options: Any) -> dict:
        return self._request("POST", "/api/manage-links.php", json={"title": title, "amount": amount, **options})

__all__ = ["TGGateway", "TGGatewayError"]

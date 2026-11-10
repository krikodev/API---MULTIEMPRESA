#!/usr/bin/env python3
"""Pruebas HTTP contra un servidor local PHP con BD SQLite ficticia."""
import json
import os
import urllib.error
import urllib.request

BASE = os.environ["API_TEST_URL"]
passed = 0


def post(payload, domain="tid.net.pe"):
    request = urllib.request.Request(
        BASE + "/get_encomiendas_dni.php",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json",
                 "X-Company-Domain": domain},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read().decode("utf-8"))


def check(label, payload, expected, status=200):
    global passed
    actual_status, result = post(payload)
    assert actual_status == status, (label, actual_status, result)
    if status == 200 and expected is not None:
        assert result["success"] is True, (label, result)
        obtained = {item["traking"] for item in result["data"]}
        assert obtained == set(expected), (label, obtained, expected)
    else:
        assert result["success"] is False, (label, result)
    passed += 1
    print("OK:", label)


check("DNI del destinatario", {"dni": "12345678", "tipo": "documento"},
      {"TID-001", "TID-004"})
check("RUC 10 del cliente del comprobante",
      {"dni": "10123456789", "tipo": "documento"}, {"TID-002"})
check("RUC 20 del cliente del comprobante; excluir entregados",
      {"dni": "20123456789", "tipo": "documento"}, {"TID-001"})
check("RUC con formato antiguo sin tipo",
      {"dni": "20123456789"}, {"TID-001"})
check("Tracking explícito",
      {"dni": "TID-001", "tipo": "tracking"}, {"TID-001"})
check("Encomienda de pago en bloque",
      {"dni": "55555555", "tipo": "documento"}, {"TID-002", "TID-005"})
check("DNI de longitud inválida",
      {"dni": "123456789", "tipo": "documento"}, None, 400)
check("RUC incompleto",
      {"dni": "2012345678", "tipo": "documento"}, None, 400)
check("Tipo de búsqueda inválido",
      {"dni": "12345678", "tipo": "desconocido"}, None, 400)

status, result = post({"dni": "12345678", "tipo": "documento"},
                      domain="otra-empresa.com")
assert status == 403 and result["success"] is False, (status, result)
passed += 1
print("OK: dominio de empresa no autorizado (fixture)")
print(f"{passed} pruebas HTTP/SQLite locales superadas")

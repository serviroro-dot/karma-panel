# Karma · Panel de centralita

Panel de operador para la nueva centralita (Asterisk). Primera versión (v0.1)
con **datos simulados**: sirve para validar el diseño y la información que
queremos ver en pantalla antes de conectarlo al sistema real.

## Qué muestra

- **Resumen del día**: llamadas totales, atendidas, perdidas, espera media y
  nivel de servicio.
- **Llamadas activas**: origen → destino, vía (cola o troncal), estado y
  duración en tiempo real.
- **Colas** (Soporte 600, Ventas 601): agentes conectados, llamadas en espera,
  espera máxima y tasa de abandono.
- **Extensiones**: estado BLF de cada extensión (disponible, en llamada,
  timbrando, no molestar, sin registrar).
- **Actividad por hora**: gráfico de llamadas atendidas y perdidas de 8:00 a
  20:00.

Incluye tema claro y oscuro (sigue la preferencia del sistema).

## Cómo verlo

Es un único archivo estático sin dependencias:

```
abrir index.html en el navegador
```

## Siguiente iteración

Sustituir el simulador de datos por la integración real con Asterisk:

1. **AMI o ARI** vía WebSocket para eventos en tiempo real (estados de
   extensión, llamadas, colas).
2. Un pequeño backend (Node) que haga de puente AMI ↔ navegador y evite
   exponer credenciales de la centralita.
3. Histórico de llamadas desde el CDR para el gráfico por horas.

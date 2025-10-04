# Health timer deprecation

The renote-health.service and renote-health.timer units have been removed.

Rationale:

- The app already exposes `/api.php?action=health` and renders a health indicator in the UI.
- Systemd timers that curl a public endpoint from the server add little value and can be misleading.
- If external monitoring is desired, use a proper monitoring system (Prometheus, UptimeRobot, etc.) targeting the endpoint.

If you previously installed those units, you can disable and remove them:

```bash
sudo systemctl disable --now renote-health.timer renote-health.service
sudo rm -f /etc/systemd/system/renote-health.timer /etc/systemd/system/renote-health.service
sudo systemctl daemon-reload
```

For write-behind flushing, continue to use `renote.service` and `renote.timer` which drive the internal batch worker.

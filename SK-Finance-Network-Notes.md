# SK Finance: how to open the app (plain serve, IP address, Tailscale)

## Which one do I use?

| | Plain `php artisan serve` | IP address (`--host=0.0.0.0`) | Tailscale |
|---|---|---|---|
| Command | `php artisan serve` | `php artisan serve --host=0.0.0.0 --port=8000` | Same command as the IP address column, with Tailscale running |
| Who can open it | Only this PC | Any device on the same wifi | Only devices signed into your Tailscale account |
| Address | `http://127.0.0.1:8000` | `http://<your-wifi-ip>:8000` (find it with `ipconfig`) | `http://<tailscale-ip>:8000` (starts with 100.) |
| Use it for | Working alone, testing | A room full of phones, like the KK Survey | Reaching your own PC from home or away, or one trusted person on their own phone |
| Not good for | Anyone else's phone | Anyone on a different network | A crowd, because everyone would have to install Tailscale |

## KK Survey day: use the IP address

1. Connect the PC to the room's wifi.
2. Start the server:
   ```
   php artisan serve --host=0.0.0.0 --port=8000
   ```
3. Run `ipconfig` and note the IPv4 address of the wifi adapter.
4. On the PC, open `http://<your-wifi-ip>:8000` and sign in as treasurer.
   Do not use `sk-finance.test` or `127.0.0.1`, because the QR codes copy whatever address you opened.
5. Click **Share sign-up (QR)** in the left menu and show the page on a screen.
6. Attendees scan the **Create account** code, choose **Viewer**, use their own email, and type the viewer code you say out loud.

## Before the day

- Add the viewer code to `.env`, then clear the config:
  ```
  SK_VIEWER_CODE=choose-a-phrase
  ```
  ```
  php artisan config:clear
  php artisan view:clear
  ```
- Test it: connect your own phone to the same wifi and open the address.
  Use wifi, not mobile data.

## Things to remember

- Your IP address changes on every network, so run `ipconfig` again after you connect.
- `SK_QR_BASE_URL` in `.env` only affects the printed QR Access ID cards.
  Viewer sign-up does not use it. Change it only if you scan Access ID cards on the new network, and reprint any cards made with the old address.
- If phones cannot open the page, the venue wifi may block devices from reaching each other.
  Try your phone's hotspot or a small router first.
- If Windows Firewall asks, allow the app on private networks.
- Do not put the app on the open internet for this event.
  Only the viewer code would stand between strangers and your records.

## After the event

- Empty `SK_VIEWER_CODE` in `.env`, then run `php artisan config:clear`.
  This stops new viewer sign-ups. Existing viewer accounts stay view-only.
- Go back to plain `php artisan serve` when you are working alone.


skc4
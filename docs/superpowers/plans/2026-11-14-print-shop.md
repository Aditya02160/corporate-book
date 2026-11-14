# Print Copies Shop: Implementation Plan

**Goal:** Besides the digital subscription, readers can buy printed copies of books: add one or more books to a cart, enter a delivery address in India, pay (Razorpay or optional cash on delivery) and follow the order until it is delivered. Authors set a print price and a shipping charge per book; delivery costs more the farther the address is from Noida, Uttar Pradesh. Administrators manage orders, stock and shipping in the dashboard.

**Two ways to buy**

| | Digital (online) | Print (offline) |
|---|---|---|
| What | Read every page online for N days | A printed copy delivered to an address |
| Where | Subscription plans (existing) | Cart, one or many books |
| Price | Plan price | Book print price + shipping |
| Paid with | Razorpay | Razorpay, or cash on delivery if turned on |

## Requirements (research summary)

- **Address (India):** full name, mobile number (10 digits, starts 6-9), email, house/flat and building, street/area, landmark (optional), city, state or union territory (36, from a list), 6-digit PIN code (first digit 1-9). India only.
- **Distance from Noida without paid APIs:** Indian PIN codes are geographic. The first two digits name a postal region (11 Delhi, 20-28 Uttar Pradesh, 56 Bengaluru, 78 Assam, ...); a few three-digit prefixes are mapped more precisely (201 Noida/Ghaziabad, 110 Delhi, 122 Gurugram, 744 Andaman, the north-eastern capitals). Each region has a reference point; the straight-line distance from Noida (28.5355 N, 77.3910 E) picks a distance band. Accurate to the band, not to the kilometre. Army post (90-99) uses the farthest band.
- **Shipping formula:** per book line: (book shipping charge + extra for the distance band) x quantity. Optional free shipping above an order amount. Bands are editable: `Up to km | Extra per book`, default `50|0, 300|30, 800|60, 1500|90, 99999|120`.
- **Taxes:** printed books are GST-exempt in India (HSN 4901); prices are shown as final. No tax lines.
- **Payment:** Razorpay order for the exact total computed on the server; signature checked; the webhook also completes print orders. Cash on delivery is an option, off by default.
- **Trust:** the browser only sends book IDs and quantities; prices, shipping, stock and totals are always computed on the server.
- **Stock:** optional per book; blank means unlimited. Reduced when an order is paid (or placed with cash on delivery), restored when it is cancelled or refunded. Quantity 1-10 per book.
- **Accounts:** buying needs a reader account (the existing sign in / sign up), so orders show on My account. The cart itself lives in the browser, so guests can fill it before signing in.

## User journey

1. Reader opens a book and reads the free preview.
2. The "preview has ended" panel offers **Subscribe** (digital) and **Buy print copy (price + shipping)** when the book is for sale in print.
3. **Subscription page:** digital plans, and a **Print copies** section listing every book for sale with cover, price and Add to cart.
4. **Cart page** (created automatically, `[corporate_book_cart]`): books, quantity +/-, remove, delivery address (saved address filled in), live shipping quote from the PIN code, total, payment choice, Place order. Guests see sign in / sign up first.
5. Payment in Razorpay Checkout, or the order is placed directly with cash on delivery.
6. Thank-you message with the order number; confirmation email.
7. **My account > My orders:** each order with books, total, status (Paid, Shipped, Delivered, ...), courier and tracking link. The saved delivery address can be edited.

## Admin journey

1. **Book editor > Book tab > Print copy:** Sell print copies (On/Off), Print price, Shipping charge (delivery near Noida), Copies in stock.
2. **Global Settings > Print tab:** Print sales on/off, distance bands, free shipping above, cash on delivery, order email address.
3. **Corporate Books > Print Orders:** list with order number, customer, books, total, payment, status, date, and status filters. Each order shows the address, phone, items, payment IDs, and has status, courier, tracking number/link and a private note. Saving a new status emails the customer (shipped with tracking, delivered, cancelled).
4. New orders email the order address.

## Order statuses

`Pending payment` (Razorpay started, not paid) -> `Paid` / `Cash on delivery` -> `Shipped` -> `Delivered`; `Cancelled` and `Refunded` from any open state. Unpaid Razorpay orders older than a day are cleaned up by the daily job.

## Build steps (test first)

1. **Shipping maths (unit tests):** PIN validation, PIN -> region point, distance from Noida, band, quote totals (quantity, stock, free shipping), address validation.
2. **Book fields and Print tab** in the editor and Global Settings.
3. **Orders:** private `cb_order` post type with custom statuses, admin list columns, order screen, emails, stock.
4. **Cart and checkout:** `[corporate_book_cart]` page, cart script, quote and checkout requests, Razorpay and cash on delivery, verify, webhook.
5. **Entry points:** lock panel button, Subscription page print section, My account orders and address.
6. **Browser tests:** author sets a print price; reader adds two books, local vs far PIN quote, pays with the test Razorpay, order appears in My orders and Print Orders; admin ships with tracking and the customer email is sent; cash on delivery; stock; bad PIN and phone.

## Not included

Courier booking or live tracking APIs, invoices as PDF, coupons, international shipping, partial refunds. Distances are by PIN region, not road distance.

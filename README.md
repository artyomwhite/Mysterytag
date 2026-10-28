# Postback Service

Невеликий сервіс, який приймає постбеки від рекламної мережі, дедуплікує їх через Redis,
зберігає у MySQL та передає партнеру через зовнішній API (з повторними спробами).

## Технології

- PHP 8.3, Slim 4 (мікрофреймворк), Guzzle (HTTP-клієнт), Predis (Redis), Monolog (логи), PDO
- MySQL 8.4 — зберігання подій та спроб доставки
- Redis 7 — дедуплікація
- Docker / Docker Compose (app + MySQL + Redis + mock партнера)

> Замість Laravel обрано Slim 4: сервісу потрібні лише роутинг, JSON-відповіді та кілька класів,
> тому повний фреймворк тут зайвий. Бізнес-логіка (`PostbackService`, `PartnerClient`, `DedupService`)
> від фреймворку не залежить і легко переноситься в Laravel.

## Запуск локально

Потрібні Docker та Docker Compose.

```bash
git clone https://github.com/artyomwhite/Mysterytag.git && cd Mysterytag
docker compose up --build
```

- Сервіс: http://localhost:8080
- Mock партнера: http://localhost:8081 (контейнер звертається до нього як `http://partner-mock:8081/api/events`)
- Схема БД створюється автоматично при старті (`bin/migrate.php`, скрипт чекає на готовність MySQL).

Конфігурація — змінні середовища в `docker-compose.yml`:

| Змінна | За замовчуванням | Опис |
|---|---|---|
| `PARTNER_API_URL` | `http://partner-mock:8081/api/events` | URL API партнера |
| `PARTNER_TIMEOUT` | `3` | таймаут запиту до партнера, с |
| `PARTNER_MAX_ATTEMPTS` | `3` | всього спроб (перша + 2 повторні) |
| `PARTNER_RETRY_DELAY_MS` | `200` | базова затримка між спробами (експоненційно: 200 мс, 400 мс) |
| `DEDUP_TTL` | `86400` | вікно дедуплікації, с (24 год) |
| `DB_*`, `REDIS_*` | див. compose | підключення до MySQL / Redis |

Щоб працювати з реальним партнером, змініть `PARTNER_API_URL`.

## API

| Метод і шлях | Опис |
|---|---|
| `GET /postback?click_id=&event=&amount=` | прийом постбеку |
| `GET /events?status=&click_id=&limit=&offset=` | список подій і результатів обробки (`status`: `pending` / `sent` / `failed`) |
| `GET /events/{id}` | подія з усіма спробами доставки |
| `GET /health` | перевірка, що сервіс живий |

Відповіді на `/postback`:

- новий валідний — `200 {"status":"accepted","message":"Event received"}`
- дублікат — `200 {"status":"duplicate","message":"Event already received"}`
- невалідні параметри — `422 {"status":"error","message":"Invalid parameters"}`
- Redis недоступний — `503` (щоб не пропустити дублікати), помилка збереження — `500`

Валідація: `click_id` і `event` непорожні (до 255 / 100 символів), `amount` — невід'ємне десяткове число
(`49`, `49.90`; без знака та експоненти).

## Як протестувати

```bash
# 1. Новий постбек -> accepted
curl "http://localhost:8080/postback?click_id=clk_123&event=purchase&amount=49.90"

# 2. Той самий постбек -> duplicate
curl "http://localhost:8080/postback?click_id=clk_123&event=purchase&amount=49.90"

# 3. Сума 49.9 = 49.90 -> теж duplicate
curl "http://localhost:8080/postback?click_id=clk_123&event=purchase&amount=49.9"

# 4. Інша сума / інша подія -> нова подія (accepted)
curl "http://localhost:8080/postback?click_id=clk_123&event=purchase&amount=50"

# 5. Невалідні параметри -> 422
curl -i "http://localhost:8080/postback?click_id=&event=purchase&amount=10"
curl -i "http://localhost:8080/postback?click_id=clk_1&event=purchase&amount=-5"
curl -i "http://localhost:8080/postback?click_id=clk_1&event=purchase&amount=abc"

# 6. Перегляд подій
curl "http://localhost:8080/events"
curl "http://localhost:8080/events?status=failed"
curl "http://localhost:8080/events?click_id=clk_123"
curl "http://localhost:8080/events/1"
```

Перевірка повторних спроб (поведінка mock-партнера залежить від префікса `click_id`):

```bash
# 500 від партнера: 3 спроби, статус failed
curl "http://localhost:8080/postback?click_id=fail500_1&event=purchase&amount=1"
# 429: теж 3 спроби
curl "http://localhost:8080/postback?click_id=rate429_1&event=purchase&amount=1"
# 400: 1 спроба, без повторів, failed
curl "http://localhost:8080/postback?click_id=fail400_1&event=purchase&amount=1"
# 503, 503, потім 200: успіх з 3-ї спроби, статус sent
curl "http://localhost:8080/postback?click_id=flaky_1&event=purchase&amount=1"
# партнер відповідає 5 с > таймаут 3 с: 3 спроби, failed (запит триватиме ~10 с)
curl "http://localhost:8080/postback?click_id=slow_1&event=purchase&amount=1"

curl "http://localhost:8080/events/<id>"   # деталі кожної спроби
docker compose logs -f app partner-mock
```

Корисно для перевірки Redis:

```bash
docker compose exec redis redis-cli --scan --pattern 'postback:dedup:*'
docker compose exec redis redis-cli TTL <ключ>        # ≈ 86400
docker compose exec redis redis-cli FLUSHDB            # скинути дедуплікацію
```

## Як реалізована дедуплікація

1. `amount` нормалізується як рядок (без перетворення у float): `49.90` → `49.9`, `049.0` → `49`.
   Тому `49.9` і `49.90` — одна сума, і немає проблем із точністю float.
2. Ключ: `postback:dedup:` + `sha256(click_id \0 event \0 normalized_amount)`.
3. Атомарна операція `SET key 1 EX 86400 NX`:
   - ключ створено → подія нова, йдемо далі;
   - ключ уже є → `duplicate`, запису в БД і запиту до партнера немає.

   Одна атомарна команда (а не «GET, потім SET») виключає гонку, коли два однакові постбеки
   приходять одночасно.
4. TTL 24 години відраховується від першого приймання; після нього така сама подія вважатиметься новою.
5. Якщо збереження в MySQL не вдалося, ключ видаляється, щоб повторний постбек від мережі не був
   помилково визнаний дублікатом події, якої ми не зберегли.

Повторні спроби відправки партнеру дедуплікацію не зачіпають: вони відбуваються всередині обробки
вже прийнятої події і не є новими постбеками.

## Як реалізовані повторні спроби

`PartnerClient` робить `POST` JSON `{"click_id","event","amount"}` на `PARTNER_API_URL`
(таймаут 3 с, connect-таймаут 2 с). Усього до 3 спроб (перша + щонайбільше 2 повторні):

| Результат | Дія |
|---|---|
| HTTP 2xx | успіх, статус `sent` |
| мережева помилка, таймаут, HTTP 429, HTTP 5xx | повтор (затримка 200 мс, потім 400 мс) |
| інший HTTP 4xx | без повторів, статус `failed` |
| спроби вичерпано | статус `failed` |

Кожна спроба логується (Monolog → stderr → `docker compose logs app`) і записується в таблицю
`delivery_attempts` (номер, HTTP-статус, помилка, тривалість). Підсумок зберігається в `events`:
`status`, `attempts`, `last_http_status`, `last_error`.

Постбек від рекламної мережі отримує `accepted`, навіть якщо доставка партнеру не вдалася:
подію ми прийняли й зберегли, а результат доставки видно через `/events`.

## Якщо партнер повідомляє, що події не надходять

Йду від початку ланцюжка до кінця: спочатку з'ясовую, де саме подія зникає.

1. **Уточнити деталі в партнера:** конкретні `click_id`/`event`/`amount`, час (з часовим поясом),
   що саме відсутнє — усе чи лише частина подій, і з якого моменту. Це відрізняє системну поломку
   (все зникло після деплою) від точкової (окремі події).
2. **Чи сервіс живий:** `GET /health`, `docker compose ps`, свіжі помилки в `docker compose logs app`,
   чи не було рестартів / деплою в той час.
3. **Чи приходять постбеки від рекламної мережі:** access-логи та лог «Postback accepted» за потрібний
   час. Якщо їх немає — проблема до нас (мережа шле на неправильний URL, ми віддаємо 4xx/5xx, блокування).
4. **Чи не відсіялися як дублікати:** шукаю відповіді `duplicate` та лог «Duplicate postback»;
   перевіряю ключ у Redis (`redis-cli --scan`, `TTL`). Дублікат за правилом — не помилка, але партнер міг
   чекати подію з іншою сумою або іншою назвою події.
5. **Чи збережено в БД:** `GET /events?click_id=...` або SQL. Немає запису при наявному `accepted` —
   помилка збереження (лог «Failed to store event»).
6. **Який статус доставки:**
   - `sent` — ми отримали 2xx від партнера; порівнюю час і тіло запиту з їхніми логами (можливо, партнер
     втрачає дані на своєму боці або дивиться не на той акаунт/середовище);
   - `failed` — дивлюсь `last_http_status`, `last_error` та `GET /events/{id}` по спробах:
     5xx/429 — проблема чи ліміти на боці партнера; 4xx — наш запит відхилено (формат, авторизація,
     неправильний URL); таймаут/DNS/TLS — мережа або доступність хоста;
   - `pending` — обробка перервалась (падіння процесу між збереженням і відправкою).
7. **Конфігурація:** значення `PARTNER_API_URL` у контейнері (`docker compose exec app printenv PARTNER_API_URL`)
   та доступність хоста з контейнера (`curl` зсередини).
8. **Відтворити вручну:** відправити `curl -X POST` з тим самим JSON на API партнера й порівняти
   відповідь із документацією (заголовки, формат `amount`, автентифікація).
9. **Оцінити масштаб:**
   `SELECT status, COUNT(*) FROM events WHERE created_at > <час> GROUP BY status;` та
   `SELECT http_status, COUNT(*) FROM delivery_attempts GROUP BY http_status;`
10. **Після виправлення:** повторно відправити `failed`-події. Нові постбеки для цього не потрібні
    (і не спрацюють, бо дедуплікація їх відсіче).

## Обмеження та можливі покращення

- Доставка партнеру виконується синхронно в запиті, тому при проблемах партнера відповідь рекламній мережі
  може тривати до ~10 с. У продакшені я б виніс доставку в чергу (Redis queue / Laravel Queue + worker).
- Немає ручного перезапуску `failed`-подій (artisan-команда / endpoint) — природне наступне покращення.
- Додати jitter до затримок, автентифікацію партнера (заголовок/підпис), метрики й алерти на частку `failed`.

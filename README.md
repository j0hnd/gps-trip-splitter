# GPS Trip Splitter (PHP 8)

Reads shuffled GPS points, cleans invalid rows, orders by time, splits into trips based on time/distance gaps, computes trip stats, and exports a **GeoJSON FeatureCollection** with each trip as a colored `LineString`. Uses **only standard PHP** (no Composer, no DB, no external APIs).

---

## 🚀 Quickstart

1. **Clone the repository** (or copy the files into a folder):

   ```bash
   git clone https://github.com/j0hnd/gps-trip-splitter.git
   cd gps-trip-splitter
   ```

2. **Create a `.env` file** with your Google Drive link:

   ```dotenv
   DRIVE_URL=https://drive.google.com/file/d/10_Aa1D5NcgRD8bDBvd_SOnvHPnk27in3/view?usp=sharing
   ```

3. **Build and run the container**:

   ```bash
   docker compose build
   docker compose run --rm gps php your_script.php
   ```

   - If `data/points.csv` exists → it will use it.  
   - If not → it will download automatically from the `DRIVE_URL` in `.env`.  
   - Outputs go to `data/trips.geojson` and `data/rejects.log`.

---

## Input

The script expects the input CSV file to be named **`points.csv`** and located in the **`data/`** folder.

CSV must contain a **header row** with columns:

- `device_id` (string)
- `lat` (decimal degrees, -90..90)
- `lon` (decimal degrees, -180..180)
- `timestamp` (ISO 8601, e.g. `2025-08-12T08:15:00Z`)

Example:

```csv
device_id,lat,lon,timestamp
devA,14.5995,120.9842,2025-08-12T08:15:00Z
devA,14.6010,120.9870,2025-08-12T08:35:00Z
```

### Auto-download behavior

If `data/points.csv` does not exist, the script will attempt to **download it automatically** from the Google Drive link defined in your **`.env`** file.

Example `.env` file:

```dotenv
DRIVE_URL=https://drive.google.com/file/d/10_Aa1D5NcgRD8bDBvd_SOnvHPnk27in3/view?usp=sharing
```

The script converts the above into a direct download link and saves the file as `data/points.csv`.

---

## How it works

1. **Clean** – discards rows with:
   - non-numeric or out-of-range coordinates
   - empty `device_id`
   - invalid ISO 8601 timestamps  
   All rejections are written to `data/rejects.log` with reason and line number.

2. **Order** – sorts remaining points by timestamp (per device).

3. **Split trips** – starts a new trip when either condition holds between consecutive points:
   - **time gap** `> 25 minutes`, or
   - **straight-line distance jump** `> 2 km` (Haversine).

4. **Stats per trip**:
   - `total_distance_km` – sum of segment distances.
   - `duration_min` – minutes between first and last point.
   - `avg_speed_kmh` – total distance divided by hours.
   - `max_speed_kmh` – maximum segment speed.
   - Metadata: `trip_id`, `device_id`, `point_count`, `start_time`, `end_time`.

5. **GeoJSON output** – `FeatureCollection` where each trip is a `Feature` with:
   - `geometry`: `LineString` [lon, lat] coordinates
   - `properties`: stats above plus basic style hints (`stroke`, `stroke-width`)

Each trip is assigned a **distinct color**.

---

## Output

- **`data/trips.geojson`** – contains all trips as a GeoJSON FeatureCollection  
- **`data/rejects.log`** – contains rows discarded during cleaning, with reasons  

---

## Usage

Run inside Docker:

```bash
docker compose run --rm gps php your_script.php
```

This will:

- Read `data/points.csv` (or auto-download it using `.env`)  
- Write `data/trips.geojson` and `data/rejects.log`

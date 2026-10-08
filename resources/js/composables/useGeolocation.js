// Position GPS du téléphone, attachée aux changements de statut quand elle est disponible.
export function currentPosition(timeout = 5000) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: Number(p.coords.latitude.toFixed(7)), lng: Number(p.coords.longitude.toFixed(7)) }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout, maximumAge: 60000 },
    )
  })
}

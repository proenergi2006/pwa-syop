// Pengenalan wajah di perangkat (face-api / TensorFlow.js). Yang dikirim ke server hanya vektor 128 angka
// (embedding), bukan foto. Dimuat malas (lazy) karena pustakanya besar; model di /models (di-cache service worker).
export const FACE_MOCK = import.meta.env.VITE_FACE_MOCK === '1' // hanya build uji; tidak ada di produksi

let ready
export function loadFace() {
  if (!ready) ready = (async () => {
    const faceapi = await import('@vladmandic/face-api')
    await faceapi.tf.ready()
    await Promise.all([
      faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
      faceapi.nets.faceLandmark68TinyNet.loadFromUri('/models'),
      faceapi.nets.faceRecognitionNet.loadFromUri('/models'),
    ])
    return faceapi
  })().catch((e) => { ready = null; throw e })
  return ready
}

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
const ear = (p) => (dist(p[1], p[5]) + dist(p[2], p[4])) / (2 * dist(p[0], p[3])) // eye aspect ratio

/** Baca satu frame video → { n, box, ear, yaw } (yaw≈0.5 menghadap lurus; <0.35 / >0.65 menoleh). */
export async function analyse(faceapi, video) {
  const all = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 })).withFaceLandmarks(true)
  if (all.length !== 1) return { n: all.length }
  const f = all[0], pts = f.landmarks.positions
  const box = f.detection.box
  const e = (ear(pts.slice(36, 42)) + ear(pts.slice(42, 48))) / 2
  const yaw = (pts[30].x - pts[0].x) / (pts[16].x - pts[0].x)
  return { n: 1, box, ear: e, yaw, vw: video.videoWidth, vh: video.videoHeight }
}

/** Ambil embedding 128-angka dari frame saat ini (null bila wajah tak terdeteksi tunggal). */
export async function describe(faceapi, video) {
  const all = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.5 })).withFaceLandmarks(true).withFaceDescriptors()
  return all.length === 1 ? Array.from(all[0].descriptor) : null
}

export function averageDescriptor(list) {
  const out = new Array(128).fill(0)
  list.forEach((d) => d.forEach((v, i) => (out[i] += v / list.length)))
  return out
}

interface ProductImageProps {
  src?: string | null
  alt?: string
  size?: number
  eager?: boolean
}

export function ProductImage({ src, alt = 'Device', size = 40, eager = false }: ProductImageProps) {
  if (!src) return null

  const url = src.startsWith('http') ? src : `${import.meta.env.BASE_URL}${src}`

  return (
    <img
      src={url}
      alt={alt}
      width={size}
      height={size}
      className="product-image"
      loading={eager ? 'eager' : 'lazy'}
    />
  )
}
